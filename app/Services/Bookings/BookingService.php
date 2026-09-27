<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\SeatCategory;
use App\Services\AuditLog;
use App\Services\Pricing\Quote;
use App\Services\Pricing\QuoteService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Services\Space\SeatHoldService;
use App\Services\Space\SpaceRuleException;
use App\Support\Clock;

/**
 * Turns a selection into a booking (spec 1.2, 8):
 *   online checkout  → status requested (Centre Manager approves — BookingWorkflow::approve)
 *   reception        → status approved (payment_due_by set; payment confirms it — PaymentService)
 *
 * Double-booking guard: inside ONE transaction the unit rows and their chairs are locked with
 * SELECT … FOR UPDATE, availability is re-checked (overlapping active bookings; other people's
 * unexpired holds), the quote is recomputed from current rates, the booking number is drawn under
 * its own row lock, and the holder's holds are converted (deleted). A concurrent checkout of the same
 * seat blocks on the lock and then sees the first booking as "occupied".
 */
final class BookingService
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityService $availability,
        private readonly SeatHoldService $holds,
        private readonly QuoteService $quotes,
        private readonly BookingNumberGenerator $numbers,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $customer customers row (id, state_code)
     * @param list<int> $unitIds
     * @param array<int|string, int|string> $addons facility id => qty
     * @param array{status?: BookingStatus, override_reason?: ?string, notes?: ?string, terms?: bool, requested_by?: ?int, created_by?: ?int, renewed_from_id?: ?int} $opts
     * @return array{booking: array<string, mixed>, quote: Quote}
     */
    public function create(array $customer, SeatHolder $holder, array $unitIds, BookingPeriod $period, array $addons, BookingSource $source, array $opts = []): array
    {
        $status = $opts['status'] ?? BookingStatus::Requested;
        $override = trim((string) ($opts['override_reason'] ?? ''));
        if ($override !== '' && !$holder->canOverride) {
            throw new SpaceRuleException('Only a Centre Manager can override a blocked or held seat.');
        }
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        if ($unitIds === []) {
            throw new SpaceRuleException('Your selection is empty — pick seats on the map first.');
        }

        return $this->db->transaction(function (Database $db) use ($customer, $holder, $unitIds, $period, $addons, $source, $status, $override, $opts): array {
            $in = implode(',', array_fill(0, count($unitIds), '?'));
            // Row locks on the units and their chairs only (not the joined layout rows).
            $db->select("SELECT id FROM seats WHERE id IN ({$in}) OR parent_id IN ({$in}) ORDER BY id FOR UPDATE", [...$unitIds, ...$unitIds]);
            $locked = $db->select(
                "SELECT s.id, s.seat_key, s.parent_id, s.code, s.kind, z.seat_category_id, sc.code AS category, lv.floor_id, f.building_id, b.centre_id
                 FROM seats s
                 JOIN zones z ON z.id = s.zone_id
                 JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
                 JOIN floors f ON f.id = lv.floor_id
                 JOIN buildings b ON b.id = f.building_id
                 LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
                 WHERE s.id IN ({$in})
                 ORDER BY s.id",
                $unitIds,
            );
            $units = $locked;
            if (count($units) !== count($unitIds) || array_filter($units, static fn (array $u) => $u['parent_id'] !== null) !== []) {
                throw new SpaceRuleException('Your selection changed — please pick your seats again.');
            }
            $cats = array_unique(array_column($units, 'category'));
            $category = count($cats) === 1 ? SeatCategory::tryFrom((string) $cats[0]) : null;
            if ($category === null) {
                throw new SpaceRuleException('Choose seats of one space type per booking.');
            }
            $this->holds->assertPeriod($category, $period, $holder);
            if ($category->wholeUnitOnly() && count($units) > 1) {
                throw new SpaceRuleException('Book one cabin / the conference room per request.');
            }

            // Re-verify availability under the locks.
            $statuses = $this->availability->seatStatuses($unitIds, $period, $holder);
            foreach ($units as $u) {
                $s = $statuses[(int) $u['id']] ?? AvailabilityService::BLOCKED;
                if (in_array($s, [AvailabilityService::AVAILABLE, AvailabilityService::MINE], true)) {
                    continue;
                }
                if ($override !== '' && in_array($s, [AvailabilityService::HELD, AvailabilityService::BLOCKED], true)) {
                    $this->audit->record('seat.override', 'seat', (int) $u['id'], ['status' => $s], ['customer_id' => (int) $customer['id']], $override);
                    continue;
                }
                throw new SpaceRuleException(match ($s) {
                    AvailabilityService::OCCUPIED => sprintf('Sorry — %s was just booked by someone else. Please pick another seat.', $u['code']),
                    AvailabilityService::HELD => sprintf('%s is being held by someone else. Please pick another seat.', $u['code']),
                    default => sprintf('%s is not available for these dates.', $u['code']),
                });
            }

            $quote = $this->quotes->quote($unitIds, $period, $addons, isset($customer['state_code']) ? (string) $customer['state_code'] : null);
            $no = $this->numbers->next((int) substr($this->clock->today(), 0, 4));
            $now = $this->clock->sql();
            $staffId = $opts['created_by'] ?? null;
            $bookingId = $db->insert('bookings', [
                'booking_no' => $no,
                'centre_id' => (int) $units[0]['centre_id'],
                'customer_id' => (int) $customer['id'],
                'source' => $source->value,
                'seat_category_id' => (int) $units[0]['seat_category_id'],
                'start_date' => $period->from,
                'end_date' => $period->to,
                'start_time' => $period->startTime,
                'end_time' => $period->endTime,
                'duration_unit' => $quote->durationUnit->value,
                'duration_qty' => $quote->durationQty,
                'seats_count' => $quote->seatCount(),
                'payment_rule' => $quote->paymentRule->value,
                'subtotal' => $quote->seatsTotal,
                'facilities_total' => $quote->addonsTotal,
                'tax_mode' => $quote->interState ? 'inter' : 'intra',
                'cgst_total' => $quote->cgst,
                'sgst_total' => $quote->sgst,
                'igst_total' => $quote->igst,
                'gst_total' => $quote->gstTotal,
                'grand_total' => $quote->grandTotal,
                'deposit_amount' => $quote->depositAmount,
                'quote_json' => json_encode($quote->toArray(), JSON_UNESCAPED_UNICODE),
                'terms_accepted_at' => !empty($opts['terms']) ? $now : null,
                'override_reason' => $override !== '' ? $override : null,
                'status' => $status->value,
                'requested_by' => $opts['requested_by'] ?? null,
                'created_by' => $staffId,
                'approved_by' => $status === BookingStatus::Approved ? $staffId : null,
                'approved_at' => $status === BookingStatus::Approved ? $now : null,
                'payment_due_by' => $status === BookingStatus::Approved ? $this->clock->now()->modify('+' . max(1, (int) setting('approval_payment_days', 7)) . ' days')->format('Y-m-d') : null,
                'renewed_from_id' => $opts['renewed_from_id'] ?? null,
                'notes' => $opts['notes'] ?? null,
            ]);
            $keyOf = array_column($units, 'seat_key', 'id');
            foreach ($quote->seats as $s) {
                $db->insert('booking_seats', [
                    'booking_id' => $bookingId,
                    'seat_id' => $s['seat_id'],
                    'seat_key' => (int) ($keyOf[$s['seat_id']] ?? $s['seat_id']), // stable across layout versions
                    'unit_price' => $s['unit_price'],
                    'gst_rate' => $s['gst_rate'],
                    'amount' => $s['amount'],
                    'start_date' => $period->from,
                    'end_date' => $period->to,
                    'start_time' => $period->startTime,
                    'end_time' => $period->endTime,
                    'allocated_at' => $status === BookingStatus::Approved ? $now : null,
                ]);
            }
            foreach ($quote->addons as $a) {
                $db->insert('booking_facilities', [
                    'booking_id' => $bookingId,
                    'facility_id' => $a['facility_id'],
                    'qty' => $a['qty'],
                    'unit_price' => $a['unit_price'],
                    'gst_rate' => $a['gst_rate'],
                    'amount' => $a['amount'],
                ]);
            }
            // Convert the holds: this booking now occupies the seats.
            $db->execute(
                "DELETE FROM seat_holds WHERE seat_id IN ({$in}) AND holder_type = ? AND holder_id = ? AND session_id = ?",
                [...$unitIds, $holder->type->value, $holder->id, $holder->sessionId],
            );
            $this->audit->record(
                'booking.create',
                'booking',
                $bookingId,
                null,
                ['booking_no' => $no, 'status' => $status->value, 'seats' => array_column($quote->seats, 'code'), 'grand_total' => $quote->grandTotal, 'source' => $source->value],
                $override !== '' ? $override : null,
                $holder->isStaff() ? 'staff' : 'account',
                $holder->id,
            );
            /** @var array<string, mixed> $booking */
            $booking = $db->first('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
            return ['booking' => $booking, 'quote' => $quote];
        });
    }
}
