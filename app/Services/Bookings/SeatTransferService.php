<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Services\AuditLog;
use App\Services\Pricing\QuoteService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SpaceRuleException;
use App\Support\Clock;
use Throwable;

/**
 * Seat handover / transfer (spec 6.3, Centre Manager `seats.handover`): move one or more seats of a booking to
 * other free seats of the same category for the rest of the tenure.
 *
 * History is kept in booking_seats: the old row gets released_at + released_reason + transferred_to_id and its
 * end_date is cut to the day before the move (when the booking had already started); a NEW row carries the new
 * seat (seat_id + its seat_key) from the effective date to the old end date. Occupancy (AvailabilityService)
 * matches on seat_key and ignores released rows, so the old seat is free and the new one taken at once.
 * Open check-ins move to the new seat.
 *
 * Pricing: the difference between the new and the old seat for the remaining period (at today's rates) is
 * SHOWN and audit-logged, never billed automatically — Finance raises an invoice / credit note if needed.
 */
final class SeatTransferService
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityService $availability,
        private readonly QuoteService $quotes,
        private readonly BookingNotifier $notifier,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * First day on the new seat: today, or the start date for bookings that have not started.
     *
     * @param array<string, mixed> $booking
     */
    public function effectiveDate(array $booking): string
    {
        return max($this->clock->today(), (string) $booking['start_date']);
    }

    /**
     * Price difference of moving one booked seat to another for the remaining period.
     *
     * @param array<string, mixed> $booking
     * @return array{from: string, to: string, old: ?float, new: ?float, diff: ?float, note: string}
     */
    public function priceDifference(array $booking, int $bookingSeatId, int $newSeatId): array
    {
        $from = $this->effectiveDate($booking);
        $period = BookingPeriod::days($from, (string) $booking['end_date']);
        $old = $this->db->first('SELECT seat_key FROM booking_seats WHERE id = ? AND booking_id = ?', [$bookingSeatId, (int) $booking['id']]);
        $state = (string) $this->db->scalar('SELECT state_code FROM customers WHERE id = ?', [(int) $booking['customer_id']]);
        $price = function (?int $seatId) use ($period, $state): ?float {
            if ($seatId === null) {
                return null;
            }
            try {
                return $this->quotes->quote([$seatId], $period, [], $state)->seatsTotal;
            } catch (Throwable) {
                return null;
            }
        };
        $oldLive = $old !== null ? ($this->availability->publishedByKey([(int) $old['seat_key']])[(int) $old['seat_key']]['id'] ?? null) : null;
        $o = $price($oldLive !== null ? (int) $oldLive : null);
        $n = $price($newSeatId);
        $diff = $o !== null && $n !== null ? round($n - $o, 2) : null;
        return [
            'from' => $period->from,
            'to' => $period->to,
            'old' => $o,
            'new' => $n,
            'diff' => $diff,
            'note' => match (true) {
                $diff === null => 'Price difference could not be calculated.',
                abs($diff) < 0.01 => 'Same price — no difference.',
                $diff > 0 => sprintf('The new seat costs %s more (before GST) for %s → %s. Not billed automatically — raise it with Finance if needed.', money($diff, 2), format_date($period->from), format_date($period->to)),
                default => sprintf('The new seat costs %s less (before GST) for %s → %s. Not refunded automatically — Finance may issue a credit note.', money(-$diff, 2), format_date($period->from), format_date($period->to)),
            },
        ];
    }

    /**
     * @param array<int|string, int|string> $moves booking_seats.id => new (published) seat id
     * @return array{moved: list<array{from: string, to: string, diff: ?float, note: string}>, booking: array<string, mixed>}
     */
    public function transfer(int $bookingId, array $moves, Actor $actor, string $reason): array
    {
        if (!$actor->can('seats.handover')) {
            throw new WorkflowException('Only a Centre Manager can hand over seats.', 'permission');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new WorkflowException('Please give a reason for the handover (at least 5 characters).', 'input');
        }
        $moves = array_filter(array_map('intval', $moves), static fn (int $v) => $v > 0);
        if ($moves === []) {
            throw new WorkflowException('Pick the new seat on the map first.', 'input');
        }
        $result = $this->db->transaction(function (Database $db) use ($bookingId, $moves, $actor, $reason): array {
            $db->select('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            $booking = (array) $db->first('SELECT b.*, sc.code AS category FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id WHERE b.id = ?', [$bookingId]);
            $status = BookingStatus::from((string) $booking['status']);
            if (!in_array($status, [BookingStatus::Approved, BookingStatus::Confirmed, BookingStatus::Active], true) || $booking['start_time'] !== null) {
                throw new WorkflowException(sprintf('Seats of a %s booking cannot be handed over.', strtolower($status->label())));
            }
            $effective = $this->effectiveDate($booking);
            if ($effective > (string) $booking['end_date']) {
                throw new WorkflowException('This booking has already ended.');
            }
            $newIds = array_values($moves);
            if (count(array_unique($newIds)) !== count($newIds)) {
                throw new WorkflowException('Pick a different new seat for each seat you move.', 'input');
            }
            $in = implode(',', array_fill(0, count($newIds), '?'));
            // same lock order as holds/bookings: seat rows first
            $db->select("SELECT id FROM seats WHERE id IN ({$in}) OR parent_id IN ({$in}) ORDER BY id FOR UPDATE", [...$newIds, ...$newIds]);
            $targets = [];
            foreach ($db->select(
                "SELECT s.id, s.seat_key, s.code, s.parent_id, sc.code AS category FROM seats s JOIN zones z ON z.id = s.zone_id
                 JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published' JOIN seat_categories sc ON sc.id = z.seat_category_id
                 WHERE s.id IN ({$in})",
                $newIds,
            ) as $t) {
                $targets[(int) $t['id']] = $t;
            }
            $period = BookingPeriod::days($effective, (string) $booking['end_date']);
            $statuses = $this->availability->seatStatuses($newIds, $period, null, $bookingId);
            $moved = [];
            $now = $this->clock->sql();
            foreach ($moves as $bsId => $newId) {
                $old = $db->first('SELECT bs.*, s.code FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.id = ? AND bs.booking_id = ? AND bs.released_at IS NULL', [(int) $bsId, $bookingId])
                    ?? throw new WorkflowException('That seat is no longer part of this booking.', 'seats');
                $t = $targets[$newId] ?? throw new WorkflowException('The new seat is not in the published layout.', 'seats');
                if ($t['parent_id'] !== null || $t['category'] !== $booking['category']) {
                    throw new WorkflowException(sprintf('%s is not a %s unit — hand over to a seat of the same type.', $t['code'], strtolower((string) $booking['category'])), 'seats');
                }
                if ((int) $t['seat_key'] === (int) $old['seat_key']) {
                    throw new WorkflowException(sprintf('%s is already this booking’s seat.', $t['code']), 'seats');
                }
                if (($statuses[$newId] ?? '') !== AvailabilityService::AVAILABLE) {
                    throw new WorkflowException(sprintf('%s is not free for %s → %s.', $t['code'], format_date($period->from), format_date($period->to)), 'seats');
                }
                $diff = $this->priceDifference($booking, (int) $old['id'], $newId);
                try {
                    $newQuote = $this->quotes->quote([$newId], $period, [], null);
                    $seatQuote = $newQuote->seats[0];
                } catch (SpaceRuleException) {
                    $seatQuote = ['unit_price' => (float) $old['unit_price'], 'gst_rate' => (float) $old['gst_rate'], 'amount' => 0.0];
                }
                $newRowId = $db->insert('booking_seats', [
                    'booking_id' => $bookingId,
                    'seat_id' => $newId,
                    'seat_key' => (int) $t['seat_key'],
                    'unit_price' => $seatQuote['unit_price'],
                    'gst_rate' => $seatQuote['gst_rate'],
                    'amount' => $seatQuote['amount'],
                    'start_date' => $effective,
                    'end_date' => (string) $old['end_date'],
                    'allocated_at' => $old['allocated_at'] !== null ? $now : null,
                ]);
                $cut = $effective > (string) $old['start_date'] ? date('Y-m-d', (int) strtotime($effective . ' -1 day')) : (string) $old['end_date'];
                $db->update('booking_seats', [
                    'released_at' => $now,
                    'released_reason' => mb_substr('handover: ' . $reason, 0, 255),
                    'transferred_to_id' => $newRowId,
                    'released_by' => $actor->staffId(),
                    'end_date' => $cut,
                ], ['id' => (int) $old['id']]);
                // move an open check-in along with the visitor
                $open = $db->first('SELECT id FROM checkins WHERE booking_seat_id = ? AND checked_out_at IS NULL', [(int) $old['id']]);
                if ($open !== null) {
                    $db->update('checkins', ['checked_out_at' => $now, 'checked_out_by' => $actor->staffId(), 'notes' => 'Moved to ' . $t['code']], ['id' => (int) $open['id']]);
                    $db->insert('checkins', ['booking_id' => $bookingId, 'booking_seat_id' => $newRowId, 'seat_id' => $newId, 'seat_key' => (int) $t['seat_key'], 'checked_in_at' => $now, 'method' => 'handover', 'handled_by' => $actor->staffId()]);
                }
                $moved[] = ['from' => (string) $old['code'], 'to' => (string) $t['code'], 'diff' => $diff['diff'], 'note' => $diff['note']];
            }
            $this->audit->record('booking.handover', 'booking', $bookingId, ['seats' => array_column($moved, 'from')], ['seats' => array_column($moved, 'to'), 'effective' => $effective, 'price_diff' => array_column($moved, 'diff')], $reason, $actor->type, $actor->id);
            return ['moved' => $moved, 'booking' => $booking, 'effective' => $effective];
        });
        $this->notifier->event($result['booking'], 'booking.handover', 'seat changed', sprintf(
            'Your seat was changed from %s. Reason: %s.',
            implode(', ', array_map(static fn (array $m) => $m['from'] . ' to ' . $m['to'], $result['moved'])) . ' from ' . format_date((string) $result['effective'], 'D, d M Y'),
            $reason,
        ));
        return ['moved' => $result['moved'], 'booking' => $result['booking']];
    }
}
