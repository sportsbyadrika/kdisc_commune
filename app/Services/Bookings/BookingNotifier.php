<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Enums\StaffRole;
use App\Services\Notify\Mailer;
use App\Support\Clock;

/**
 * Booking emails + in-app notification rows (notifications table), for visitors and staff.
 *
 *   transitioned()  every status change: the visitor gets a branded "booking-update" email; Centre Managers and
 *                   receptionists (except whoever did it) get a short "staff-booking-update" email
 *   event()         other visitor-facing events (seat handover, early exit, extension created)
 *   renewalDue()    15/7/1-day reminders — idempotent through notifications.dedupe_key
 *
 * Mailer::send() never throws, so a mail outage never blocks a booking action.
 */
final class BookingNotifier
{
    public function __construct(private readonly Database $db, private readonly Mailer $mailer, private readonly Clock $clock)
    {
    }

    /**
     * @param array<string, mixed> $booking bookings row joined with customer (BookingDirectory::findByNo) or plain
     * @param array<string, mixed> $extra   extra template data (e.g. requirement, dues)
     */
    public function transitioned(array $booking, BookingStatus $from, BookingStatus $to, Actor $actor, ?string $reason = null, array $extra = []): void
    {
        $booking = $this->full($booking);
        $no = (string) $booking['booking_no'];
        [$headline, $intro] = $this->copy($booking, $to, $reason, $extra);
        $this->toVisitor($booking, "Booking {$no}: {$headline}", 'booking.' . $to->value, $headline, $intro, $extra + ['reason' => $reason, 'status' => $to]);
        $this->toStaff($booking, $actor, sprintf('%s %s → %s', $no, $from->label(), $to->label()), sprintf(
            '%s (%s) — %s → %s%s by %s.',
            $booking['customer_name'] ?? 'Visitor',
            $booking['unique_id'] ?? '—',
            $from->label(),
            $to->label(),
            $reason !== null && $reason !== '' ? ' (' . $reason . ')' : '',
            $actor->isSystem() ? 'the system' : ($actor->isVisitor() ? 'the visitor' : ($actor->name !== '' ? $actor->name : 'staff')),
        ));
    }

    /**
     * A new online request — tell the managers (visitor already got "booking-requested").
     *
     * @param array<string, mixed> $booking
     */
    public function requested(array $booking): void
    {
        $booking = $this->full($booking);
        $this->toStaff($booking, Actor::system(), 'New booking request ' . $booking['booking_no'], sprintf(
            '%s (%s) requested %s · %s for %s → %s. Review and approve it in the bookings console.',
            $booking['customer_name'] ?? 'Visitor',
            $booking['unique_id'] ?? '—',
            $booking['category_name'] ?? '',
            $booking['seat_codes'] ?? '',
            format_date((string) $booking['start_date']),
            format_date((string) $booking['end_date']),
        ), [StaffRole::CentreManager]);
    }

    /**
     * Visitor-facing event that is not a status change (seat moved, early exit, extension).
     *
     * @param array<string, mixed> $booking
     * @param list<array{0: string, 1: string}> $rows
     */
    public function event(array $booking, string $type, string $headline, string $intro, array $rows = []): void
    {
        $booking = $this->full($booking);
        $this->toVisitor($booking, 'Booking ' . $booking['booking_no'] . ': ' . $headline, $type, $headline, $intro, ['rows' => $rows]);
    }

    /**
     * Renewal reminder N days before the end date. Returns false when it was already sent (idempotent).
     *
     * @param array<string, mixed> $booking
     */
    public function renewalDue(array $booking, int $days): bool
    {
        $booking = $this->full($booking);
        $key = sprintf('renewal.%d.%d.%s', (int) $booking['id'], $days, (string) $booking['end_date']);
        $recipient = $this->recipient($booking);
        $headline = $days === 1 ? 'ends tomorrow' : "ends in {$days} days";
        $intro = sprintf(
            'Your %s booking (%s) ends on %s. Renew now to keep the same seats — we’ll re-quote at the current rate.',
            $booking['category_name'] ?? '',
            $booking['seat_codes'] ?? '',
            format_date((string) $booking['end_date'], 'D, d M Y'),
        );
        $inserted = $this->log('customer', (int) $booking['customer_id'], 'renewal.due', 'Booking ' . $booking['booking_no'] . ' ' . $headline, $intro, ['booking_no' => $booking['booking_no'], 'days' => $days], $key, $recipient !== null ? 'database,email' : 'database');
        if (!$inserted) {
            return false;
        }
        if ($recipient !== null) {
            $this->mailer->send([$recipient['email'], $recipient['name']], 'Booking ' . $booking['booking_no'] . ' ' . $headline, 'booking-update', [
                'name' => $recipient['name'],
                'booking' => $booking,
                'headline' => 'Your booking ' . $headline,
                'intro' => $intro,
                'button' => ['label' => 'Renew my booking', 'url' => absolute_url('portal.bookings.show', ['no' => $booking['booking_no']])],
            ]);
            $this->db->execute('UPDATE notifications SET emailed_at = ? WHERE dedupe_key = ?', [$this->clock->sql(), $key]);
        }
        return true;
    }

    /**
     * Append a notification row; with a dedupe key a second call is a no-op (returns false).
     *
     * @param array<string, mixed> $data
     */
    public function log(string $recipientType, int $recipientId, string $type, string $title, string $body, array $data = [], ?string $dedupeKey = null, string $channels = 'database'): bool
    {
        $n = $this->db->execute(
            'INSERT IGNORE INTO notifications (recipient_type, recipient_id, type, dedupe_key, title, body, data, channels, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$recipientType, $recipientId, $type, $dedupeKey, mb_substr($title, 0, 190), $body, json_encode($data, JSON_UNESCAPED_UNICODE), $channels, $this->clock->sql()],
        );
        return $n > 0;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $data
     */
    private function toVisitor(array $booking, string $subject, string $type, string $headline, string $intro, array $data = []): void
    {
        $this->log('customer', (int) $booking['customer_id'], $type, $subject, $intro, ['booking_no' => $booking['booking_no']]);
        $recipient = $this->recipient($booking);
        if ($recipient === null) {
            return;
        }
        $this->mailer->send([$recipient['email'], $recipient['name']], $subject, 'booking-update', $data + [
            'name' => $recipient['name'],
            'booking' => $booking,
            'headline' => $headline,
            'intro' => $intro,
            'button' => ['label' => 'View my booking', 'url' => absolute_url('portal.bookings.show', ['no' => $booking['booking_no']])],
        ]);
    }

    /**
     * @param array<string, mixed> $booking
     * @param list<StaffRole> $roles
     */
    private function toStaff(array $booking, Actor $actor, string $subject, string $message, array $roles = [StaffRole::CentreManager, StaffRole::Receptionist]): void
    {
        $in = implode(',', array_fill(0, count($roles), '?'));
        $staff = $this->db->select(
            "SELECT id, name, email FROM staff_users WHERE is_active = 1 AND role IN ({$in}) AND (centre_id IS NULL OR centre_id = ?)",
            [...array_map(static fn (StaffRole $r) => $r->value, $roles), (int) ($booking['centre_id'] ?? 0)],
        );
        foreach ($staff as $s) {
            if ($actor->isStaff() && (int) $s['id'] === $actor->id) {
                continue;
            }
            $this->log('staff', (int) $s['id'], 'booking.staff', $subject, $message, ['booking_no' => $booking['booking_no']]);
            $this->mailer->send([(string) $s['email'], (string) $s['name']], $subject, 'staff-booking-update', [
                'name' => (string) $s['name'],
                'booking' => $booking,
                'message' => $message,
                'url' => absolute_url('staff.bookings.show', ['no' => $booking['booking_no']]),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{email: string, name: string}|null
     */
    private function recipient(array $booking): ?array
    {
        $email = trim((string) ($booking['customer_email'] ?? ''));
        if ($email === '' && !empty($booking['customer_id'])) {
            $email = (string) $this->db->scalar(
                'SELECT COALESCE(NULLIF(c.email, \'\'), a.email) FROM customers c LEFT JOIN accounts a ON a.id = c.account_id WHERE c.id = ?',
                [(int) $booking['customer_id']],
            );
        }
        return $email !== '' ? ['email' => $email, 'name' => (string) ($booking['customer_name'] ?? '')] : null;
    }

    /**
     * Joined booking row (customer name/email, seat codes, category) for templates.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private function full(array $booking): array
    {
        if (isset($booking['customer_name'], $booking['seat_codes'])) {
            return $booking;
        }
        $row = $this->db->first(
            "SELECT b.*, sc.name AS category_name, c.name AS customer_name, c.email AS customer_email, c.unique_id,
                    (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seat_codes
             FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id JOIN customers c ON c.id = b.customer_id WHERE b.id = ?",
            [(int) $booking['id']],
        );
        return $row ?? $booking;
    }

    /**
     * Headline + intro paragraph for a status email.
     *
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $extra
     * @return array{0: string, 1: string}
     */
    private function copy(array $booking, BookingStatus $to, ?string $reason, array $extra): array
    {
        $req = $extra['requirement'] ?? null;
        $payBy = !empty($booking['payment_due_by']) ? format_date((string) $booking['payment_due_by'], 'D, d M Y') : null;
        return match ($to) {
            BookingStatus::Approved => ['approved — please pay to confirm', sprintf(
                'Good news — the Centre Manager approved your booking. To confirm and get your seats allotted, pay %s at the front desk%s. %s',
                is_array($req) ? money($req['total'], fmod((float) $req['total'], 1.0) ? 2 : 0) : 'the required amount',
                $payBy !== null ? ' by ' . $payBy : '',
                is_array($req) ? (string) $req['summary'] : '',
            )],
            BookingStatus::Confirmed => ['confirmed — your seats are allotted', sprintf('We received your payment and your seats are allotted. See you on %s — show your Unique ID QR at the front desk to check in.', format_date((string) $booking['start_date'], 'D, d M Y'))],
            BookingStatus::Active => ['has started', 'Welcome to Commune! Your booking is now active. Have a productive stay.'],
            BookingStatus::Completed => ['is complete', 'Your booking is complete. Thank you for working with us — you can renew or book again from your portal any time.'],
            BookingStatus::Rejected => ['could not be approved', 'Sorry — the Centre Manager could not approve this request' . ($reason ? ': ' . $reason : '.') . ' The seats were released. Call the front desk if you would like help finding an alternative.'],
            BookingStatus::Cancelled => ['was cancelled', 'This booking was cancelled' . ($reason ? ': ' . $reason : '.') . ' The seats were released.'],
            BookingStatus::Requested => ['request received', 'We received your request.'],
        };
    }
}
