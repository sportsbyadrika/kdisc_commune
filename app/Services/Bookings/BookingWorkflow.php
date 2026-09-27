<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Enums\KycStatus;
use App\Services\AuditLog;
use App\Services\Payments\PaymentLedger;
use App\Services\SettingsService;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The booking state machine (spec 1.2, 6.1–6.3, 7.2). Every status change goes through transition():
 * the booking row is locked, BookingStatus::canTransitionTo() is enforced, the change is audit-logged as
 * `booking.{status}` with actor + reason, and the visitor + staff are notified (BookingNotifier).
 *
 *   approve   requested → approved   Centre Manager (bookings.approve); customer KYC must be verified.
 *                                    Sets payment_due_by = today + setting('approval_payment_days').
 *   reject    requested → rejected   bookings.approve + reason; releases the seats.
 *   confirm   approved  → confirmed  when ConfirmationRule is met (PaymentService calls confirmIfReady() after
 *                                    every payment) and KYC is verified; allots the seats (allocated_at).
 *   activate  confirmed → active     first check-in (CheckinService) or the start date (bookings:tick).
 *   complete  active    → completed  check-out on/after the end date, or the end date has passed (tick).
 *   cancel    requested|approved|confirmed → cancelled   visitor: own requested/approved; staff
 *                                    (bookings.cancel) with a reason; releases the seats.
 *   expire    approved → cancelled   approved but unpaid after payment_due_by (tick, actor = system).
 *   earlyExit active: shortens the tenure (seats released from a date); completes at once when that date has come.
 */
final class BookingWorkflow
{
    public function __construct(
        private readonly Database $db,
        private readonly PaymentLedger $ledger,
        private readonly BookingNotifier $notifier,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function approve(int $bookingId, Actor $actor, ?string $note = null): array
    {
        $this->requireAbility($actor, 'bookings.approve', 'Only a Centre Manager can approve booking requests.');
        $days = max(1, (int) $this->settings->get('approval_payment_days', 7));
        $dueBy = (new DateTimeImmutable($this->clock->today()))->modify("+{$days} days")->format('Y-m-d');
        $booking = $this->transition($bookingId, BookingStatus::Approved, $actor, $note, function (array $b): array {
            $this->requireKyc($b);
            return [];
        }, fn (): array => ['approved_by' => $actor->staffId(), 'approved_at' => $this->clock->sql(), 'payment_due_by' => $dueBy]);
        $this->ledger->schedule($booking); // rent periods exist from approval on (security-deposit bookings)
        $this->confirmIfReady($bookingId, $actor);
        return $this->find($bookingId);
    }

    /** @return array<string, mixed> */
    public function reject(int $bookingId, Actor $actor, string $reason): array
    {
        $this->requireAbility($actor, 'bookings.approve', 'Only a Centre Manager can reject booking requests.');
        $reason = $this->requireReason($reason);
        return $this->transition($bookingId, BookingStatus::Rejected, $actor, $reason, null, function (array $b) use ($reason, $actor): array {
            $this->releaseSeats((int) $b['id'], 'rejected', $actor);
            return ['rejected_reason' => $reason, 'cancelled_at' => $this->clock->sql(), 'cancelled_by' => $actor->tag()];
        });
    }

    /**
     * Manual confirm (e.g. payment was logged before KYC got verified).
     *
     * @return array<string, mixed>
     */
    public function confirm(int $bookingId, Actor $actor, ?string $note = null): array
    {
        if (!$actor->isSystem() && !$actor->can('payments.log')) {
            throw new WorkflowException('Your role cannot confirm bookings.', 'permission');
        }
        return $this->transition($bookingId, BookingStatus::Confirmed, $actor, $note, function (array $b): array {
            $this->requireKyc($b);
            $dues = $this->ledger->dues($b);
            if (!$dues['confirmation_met']) {
                throw new WorkflowException(sprintf(
                    'The confirmation payment is not complete yet — %s is required (%s); %s logged so far.',
                    money($dues['requirement']['total'], 2),
                    $dues['requirement']['label'],
                    money($dues['paid'], 2),
                ), 'payment');
            }
            return ['dues' => $dues];
        }, function (array $b): array {
            $now = $this->clock->sql();
            $this->db->execute('UPDATE booking_seats SET allocated_at = ? WHERE booking_id = ? AND allocated_at IS NULL AND released_at IS NULL', [$now, (int) $b['id']]);
            return ['confirmed_at' => $now];
        });
    }

    /** approved → confirmed when the requirement is met and KYC is verified; otherwise a no-op. */
    public function confirmIfReady(int $bookingId, Actor $actor): bool
    {
        $b = $this->find($bookingId);
        if ($b['status'] !== BookingStatus::Approved->value || ($b['kyc_status'] ?? '') !== KycStatus::Verified->value) {
            return false;
        }
        if (!$this->ledger->dues($b)['confirmation_met']) {
            return false;
        }
        $this->confirm($bookingId, $actor->isStaff() && !$actor->can('payments.log') ? Actor::system() : $actor, 'Confirmation payment received');
        return true;
    }

    /** @return array<string, mixed> */
    public function activate(int $bookingId, Actor $actor, ?string $reason = null): array
    {
        return $this->transition($bookingId, BookingStatus::Active, $actor, $reason, function (array $b): array {
            if ((string) $b['start_date'] > $this->clock->today()) {
                throw new WorkflowException('This booking starts on ' . format_date((string) $b['start_date']) . ' — it cannot start early.');
            }
            return [];
        }, fn (): array => ['activated_at' => $this->clock->sql()]);
    }

    /** @return array<string, mixed> */
    public function complete(int $bookingId, Actor $actor, ?string $reason = null): array
    {
        return $this->transition($bookingId, BookingStatus::Completed, $actor, $reason, null, function (array $b) use ($actor): array {
            $now = $this->clock->sql();
            $this->db->execute(
                'UPDATE checkins SET checked_out_at = ?, checked_out_by = ? WHERE booking_id = ? AND checked_out_at IS NULL',
                [$now, $actor->staffId(), (int) $b['id']],
            );
            return ['completed_at' => $now];
        });
    }

    /** @return array<string, mixed> */
    public function cancel(int $bookingId, Actor $actor, string $reason): array
    {
        $reason = trim($reason);
        return $this->transition($bookingId, BookingStatus::Cancelled, $actor, $reason !== '' ? $reason : null, function (array $b) use ($actor, $reason): array {
            $status = BookingStatus::from((string) $b['status']);
            if ($actor->isVisitor()) {
                if ((int) $b['customer_id'] !== $actor->customerId) {
                    throw new WorkflowException('This is not your booking.', 'permission');
                }
                if (!$status->visitorCanCancel()) {
                    throw new WorkflowException('Confirmed bookings can only be cancelled at the front desk.', 'permission');
                }
            } elseif ($actor->isStaff()) {
                if (!$actor->can('bookings.cancel')) {
                    throw new WorkflowException('Your role cannot cancel bookings.', 'permission');
                }
                $this->requireReason($reason);
            }
            return [];
        }, function (array $b) use ($actor, $reason): array {
            $this->releaseSeats((int) $b['id'], 'cancelled', $actor);
            $this->db->execute("UPDATE rent_schedules SET status = 'cancelled' WHERE booking_id = ? AND status = 'open'", [(int) $b['id']]);
            return ['cancelled_at' => $this->clock->sql(), 'cancel_reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null, 'cancelled_by' => $actor->tag()];
        });
    }

    /** Approved-but-unpaid past payment_due_by → cancelled (system). Returns false when not expirable. */
    public function expire(int $bookingId): bool
    {
        $b = $this->find($bookingId);
        if ($b['status'] !== BookingStatus::Approved->value || empty($b['payment_due_by']) || (string) $b['payment_due_by'] >= $this->clock->today()) {
            return false;
        }
        if ($this->ledger->dues($b)['confirmation_met']) {
            return false; // paid, only waiting for KYC — never expire money that was received
        }
        $this->cancel($bookingId, Actor::system(), 'Payment not received by ' . format_date((string) $b['payment_due_by']) . ' — approval expired');
        return true;
    }

    /**
     * Early exit of an active booking: the seats are released from $releaseFrom (last day = the day before).
     * Rent periods after the last day are cancelled; nothing is re-billed automatically (Finance issues credit
     * notes). When the last day is already past, the booking completes immediately.
     *
     * @return array<string, mixed>
     */
    public function earlyExit(int $bookingId, Actor $actor, string $releaseFrom, string $reason): array
    {
        if (!$actor->can('bookings.cancel')) {
            throw new WorkflowException('Your role cannot end bookings early.', 'permission');
        }
        $reason = $this->requireReason($reason);
        $today = $this->clock->today();
        $booking = $this->db->transaction(function (Database $db) use ($bookingId, $actor, $releaseFrom, $reason, $today): array {
            $b = $this->lock($bookingId);
            if ($b['status'] !== BookingStatus::Active->value) {
                throw new WorkflowException('Only an active booking can end early — cancel it instead.');
            }
            $min = max($today, (new DateTimeImmutable((string) $b['start_date']))->modify('+1 day')->format('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseFrom) || $releaseFrom < $min || $releaseFrom > (string) $b['end_date']) {
                throw new WorkflowException(sprintf('Choose a release date between %s and %s.', format_date($min), format_date((string) $b['end_date'])), 'input');
            }
            $lastDay = (new DateTimeImmutable($releaseFrom))->modify('-1 day')->format('Y-m-d');
            $db->update('bookings', [
                'original_end_date' => $b['original_end_date'] ?? $b['end_date'],
                'end_date' => $lastDay,
                'exit_reason' => mb_substr($reason, 0, 500),
            ], ['id' => $bookingId]);
            $db->execute('UPDATE booking_seats SET end_date = ? WHERE booking_id = ? AND released_at IS NULL AND end_date > ?', [$lastDay, $bookingId, $lastDay]);
            $this->ledger->schedule($b);
            $cancelled = $this->ledger->cancelPeriodsAfter($bookingId, $lastDay);
            $this->audit->record('booking.early_exit', 'booking', $bookingId, ['end_date' => $b['end_date']], ['end_date' => $lastDay, 'release_from' => $releaseFrom, 'rent_periods_cancelled' => $cancelled], $reason, $this->actorType($actor), $actor->id);
            return $this->find($bookingId);
        });
        $this->notifier->event($booking, 'booking.early_exit', 'ends early', sprintf(
            'Your booking now ends on %s (seats released from %s). Reason: %s. Any refund or adjustment is handled by our finance team.',
            format_date((string) $booking['end_date'], 'D, d M Y'),
            format_date($releaseFrom, 'D, d M Y'),
            $reason,
        ));
        if ((string) $booking['end_date'] < $today) {
            return $this->complete($bookingId, $actor, 'Early exit: ' . $reason);
        }
        return $booking;
    }

    /**
     * Actions available on a booking for an actor (buttons on the detail page).
     *
     * @param array<string, mixed> $booking
     * @return array<string, bool>
     */
    public function actions(array $booking, Actor $actor): array
    {
        $s = BookingStatus::from((string) $booking['status']);
        $today = $this->clock->today();
        $inDates = (string) $booking['start_date'] <= $today && (string) $booking['end_date'] >= $today;
        return [
            'approve' => $s === BookingStatus::Requested && $actor->can('bookings.approve'),
            'reject' => $s === BookingStatus::Requested && $actor->can('bookings.approve'),
            'log_payment' => in_array($s, [BookingStatus::Approved, BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed], true) && $actor->can('payments.log'),
            'confirm' => $s === BookingStatus::Approved && $actor->can('payments.log'),
            'check_in' => in_array($s, [BookingStatus::Confirmed, BookingStatus::Active], true) && $inDates && $actor->can('checkins.manage'),
            'handover' => in_array($s, [BookingStatus::Approved, BookingStatus::Confirmed, BookingStatus::Active], true) && (string) $booking['end_date'] >= $today && $booking['start_time'] === null && $actor->can('seats.handover'),
            'extend' => in_array($s, [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed], true) && $booking['start_time'] === null && $actor->can('bookings.extend'),
            'early_exit' => $s === BookingStatus::Active && (string) $booking['end_date'] > $today && $actor->can('bookings.cancel'),
            'cancel' => $s->isPreActive() && $actor->can('bookings.cancel'),
            'void_payment' => $actor->can('payments.void'),
        ];
    }

    // ------------------------------------------------------------------ core

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $guard  checks under the lock; may return extra notify data
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $apply  side effects under the lock; returns booking columns to set
     * @return array<string, mixed> the booking after the change
     */
    private function transition(int $bookingId, BookingStatus $to, Actor $actor, ?string $reason, ?callable $guard = null, ?callable $apply = null): array
    {
        [$booking, $from, $extra] = $this->db->transaction(function (Database $db) use ($bookingId, $to, $actor, $reason, $guard, $apply): array {
            $b = $this->lock($bookingId);
            $from = BookingStatus::from((string) $b['status']);
            if (!$from->canTransitionTo($to)) {
                throw new WorkflowException(sprintf('Booking %s is %s — it cannot be %s.', $b['booking_no'], strtolower($from->label()), $to->verb()));
            }
            $extra = $guard !== null ? $guard($b) : [];
            $fields = $apply !== null ? $apply($b) : [];
            $db->update('bookings', ['status' => $to->value] + $fields, ['id' => $bookingId]);
            $this->audit->record(
                'booking.' . $to->value,
                'booking',
                $bookingId,
                ['status' => $from->value],
                ['status' => $to->value] + array_filter($fields, static fn ($v) => is_scalar($v)),
                $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                $this->actorType($actor),
                $actor->id,
            );
            return [$this->find($bookingId), $from, $extra];
        });
        logger()->info('Booking {no}: {from} → {to} by {actor}', ['no' => $booking['booking_no'], 'from' => $from->value, 'to' => $to->value, 'actor' => $actor->tag()]);
        $notify = [];
        if ($to === BookingStatus::Approved) {
            $notify['requirement'] = $this->ledger->dues($booking)['requirement'];
        }
        $this->notifier->transitioned($booking, $from, $to, $actor, $reason, $notify + (is_array($extra) ? array_intersect_key($extra, ['requirement' => 1]) : []));
        return $booking;
    }

    /** @return array<string, mixed> */
    private function lock(int $bookingId): array
    {
        $this->db->select('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
        return $this->find($bookingId);
    }

    /** @return array<string, mixed> booking + customer KYC status */
    public function find(int $bookingId): array
    {
        return $this->db->first(
            'SELECT b.*, c.kyc_status, c.name AS customer_name, c.unique_id, c.email AS customer_email FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = ?',
            [$bookingId],
        ) ?? throw new WorkflowException('Booking not found.', 'input');
    }

    /** @param array<string, mixed> $b */
    private function requireKyc(array $b): void
    {
        if (($b['kyc_status'] ?? '') !== KycStatus::Verified->value) {
            throw new WorkflowException(
                sprintf('Verify KYC first — %s’s KYC is %s. Bookings can only be approved or confirmed for verified visitors.', $b['customer_name'] ?? 'the visitor', strtolower(preg_replace('/^KYC\s+/i', '', (KycStatus::tryFrom((string) ($b['kyc_status'] ?? '')) ?? KycStatus::NotSubmitted)->label()) ?? '')),
                'kyc',
                url('staff.kyc.show', ['id' => (int) $b['customer_id']]),
                'Open KYC review',
            );
        }
    }

    private function requireAbility(Actor $actor, string $ability, string $message): void
    {
        if (!$actor->can($ability)) {
            throw new WorkflowException($message, 'permission');
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new WorkflowException('Please give a reason (at least 5 characters).', 'input');
        }
        return mb_substr($reason, 0, 500);
    }

    private function releaseSeats(int $bookingId, string $why, Actor $actor): void
    {
        $this->db->execute(
            'UPDATE booking_seats SET released_at = ?, released_reason = ?, released_by = ? WHERE booking_id = ? AND released_at IS NULL',
            [$this->clock->sql(), $why, $actor->staffId(), $bookingId],
        );
    }

    private function actorType(Actor $actor): string
    {
        return $actor->type;
    }
}
