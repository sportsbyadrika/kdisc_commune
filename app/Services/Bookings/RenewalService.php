<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Services\AuditLog;
use App\Services\Pricing\Duration;
use App\Services\Pricing\Quote;
use App\Services\Pricing\QuoteService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Services\Space\SpaceRuleException;
use App\Support\Clock;
use DateTimeImmutable;
use Throwable;

/**
 * Renewals / extensions (spec 6.1 "extend/renew", 6.3 "renewals list").
 *
 * An extension is a NEW booking linked by bookings.renewed_from_id, starting the day after the current end date
 * (or today when that is already past). Seats are matched by seats.seat_key to the rows of the current published
 * layout; a seat that is no longer free for the new dates must be replaced by another free unit of the same
 * category (the page asks). The new booking is quoted at the rate effective on its own start date (so a rate
 * change after the original booking applies) and follows its own payment rule; staff-created extensions start
 * as `approved` like reception bookings. Visitors renew through the Space Explorer (?renew={bookingNo}), which
 * preselects the same seats and dates and creates a `requested` booking linked the same way.
 */
final class RenewalService
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityService $availability,
        private readonly QuoteService $quotes,
        private readonly BookingService $bookings,
        private readonly BookingNotifier $notifier,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * First day of the follow-on booking.
     *
     * @param array<string, mixed> $booking
     */
    public function startDate(array $booking): string
    {
        $next = (new DateTimeImmutable((string) $booking['end_date']))->modify('+1 day')->format('Y-m-d');
        return max($next, $this->clock->today());
    }

    /**
     * Default end: the same length as the original tenure.
     *
     * @param array<string, mixed> $booking
     */
    public function defaultEnd(array $booking): string
    {
        $d = Duration::of(BookingPeriod::days((string) $booking['start_date'], (string) (($booking['original_end_date'] ?? '') ?: $booking['end_date'])));
        $from = new DateTimeImmutable($this->startDate($booking));
        $end = Duration::addMonths($from, $d->months);
        if ($d->days > 0) {
            $end = $end->modify('+' . $d->days . ' days');
        }
        return $end->modify('-1 day')->format('Y-m-d');
    }

    /**
     * What an extension would look like: per current seat its live row, whether it is free, alternatives, and a quote.
     *
     * @param array<string, mixed> $booking bookings row (+ category code as `category`)
     * @param array<int|string, int|string> $replace seat_key => replacement seat id
     * @return array<string, mixed>
     */
    public function proposal(array $booking, ?string $to = null, array $replace = []): array
    {
        if ($booking['start_time'] !== null) {
            throw new WorkflowException('Hourly conference bookings are not extended — book another slot on the map.');
        }
        $from = $this->startDate($booking);
        $to = $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 && $to >= $from ? $to : $this->defaultEnd($booking);
        $period = BookingPeriod::days($from, $to);
        $current = $this->db->select(
            'SELECT bs.seat_key, s.code FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = ? AND bs.released_at IS NULL ORDER BY s.code',
            [(int) $booking['id']],
        );
        if ($current === []) {
            // a completed booking whose seats were released (e.g. early exit) — fall back to the last seats held
            $current = $this->db->select('SELECT bs.seat_key, s.code FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = ? AND bs.transferred_to_id IS NULL ORDER BY s.code', [(int) $booking['id']]);
        }
        $live = $this->availability->publishedByKey(array_map(static fn (array $r) => (int) $r['seat_key'], $current));
        $ids = array_values(array_filter(array_map(static fn (array $l) => (int) $l['id'], $live)));
        $statuses = $this->availability->seatStatuses($ids, $period);
        $category = (string) ($booking['category'] ?? $this->db->scalar('SELECT code FROM seat_categories WHERE id = ?', [(int) $booking['seat_category_id']]));
        $floorId = $live !== [] ? (int) array_values($live)[0]['floor_id'] : null;
        $free = null;
        $rows = [];
        $chosen = [];
        foreach ($current as $c) {
            $key = (int) $c['seat_key'];
            $l = $live[$key] ?? null;
            $status = $l !== null ? ($statuses[(int) $l['id']] ?? AvailabilityService::BLOCKED) : 'removed';
            $ok = $status === AvailabilityService::AVAILABLE;
            $row = ['seat_key' => $key, 'code' => $l['code'] ?? $c['code'], 'seat_id' => $l !== null ? (int) $l['id'] : null, 'status' => $status, 'available' => $ok, 'replacement' => null, 'alternatives' => []];
            if (!$ok) {
                $free ??= $this->availability->freeUnits($category, $period, null, $floorId);
                $row['alternatives'] = array_map(static fn (array $f) => ['id' => (int) $f['id'], 'code' => (string) $f['code'], 'floor' => (string) $f['floor_name'], 'zone' => (string) $f['zone_name']], array_slice($free, 0, 60));
                $pick = (int) ($replace[$key] ?? 0);
                if ($pick > 0 && in_array($pick, array_column($row['alternatives'], 'id'), true)) {
                    $row['replacement'] = $pick;
                    $chosen[] = $pick;
                }
            } else {
                $chosen[] = (int) $l['id'];
            }
            $rows[] = $row;
        }
        $complete = count($chosen) === count($rows) && count(array_unique($chosen)) === count($chosen);
        $quote = null;
        $error = null;
        if ($complete && $chosen !== []) {
            try {
                $quote = $this->quotes->quote($chosen, $period, $this->addons((int) $booking['id']), (string) $this->db->scalar('SELECT state_code FROM customers WHERE id = ?', [(int) $booking['customer_id']]));
            } catch (SpaceRuleException $e) {
                $error = $e->getMessage();
            }
        }
        return ['from' => $from, 'to' => $to, 'seats' => $rows, 'seat_ids' => $chosen, 'complete' => $complete && $error === null, 'quote' => $quote, 'error' => $error, 'floor_id' => $floorId];
    }

    /**
     * Create the follow-on booking (status approved, source reception) for staff.
     *
     * @param array<string, mixed> $booking
     * @param array<int|string, int|string> $replace seat_key => replacement seat id
     * @return array{booking: array<string, mixed>, quote: Quote}
     */
    public function extend(array $booking, string $to, array $replace, Actor $actor, ?string $notes = null): array
    {
        if (!$actor->can('bookings.extend')) {
            throw new WorkflowException('Your role cannot extend bookings.', 'permission');
        }
        $status = BookingStatus::from((string) $booking['status']);
        if (!in_array($status, [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed], true)) {
            throw new WorkflowException(sprintf('A %s booking cannot be extended.', strtolower($status->label())));
        }
        $p = $this->proposal($booking, $to, $replace);
        if (!$p['complete']) {
            throw new WorkflowException($p['error'] ?? 'Some seats are taken for the new dates — pick a replacement for each.', 'seats');
        }
        $customer = (array) $this->db->first('SELECT * FROM customers WHERE id = ?', [(int) $booking['customer_id']]);
        $holder = SeatHolder::staff((int) $actor->id, 'renewal-' . bin2hex(random_bytes(6)), (int) $customer['id']);
        try {
            $result = $this->bookings->create($customer, $holder, $p['seat_ids'], BookingPeriod::days($p['from'], $p['to']), $this->addons((int) $booking['id']), BookingSource::Reception, [
                'status' => BookingStatus::Approved,
                'created_by' => $actor->staffId(),
                'renewed_from_id' => (int) $booking['id'],
                'notes' => $notes !== null && $notes !== '' ? $notes : 'Extension of ' . $booking['booking_no'],
                'terms' => true,
            ]);
        } catch (SpaceRuleException $e) {
            throw new WorkflowException($e->getMessage(), 'seats');
        }
        $new = $result['booking'];
        $this->audit->record('booking.extend', 'booking', (int) $booking['id'], null, ['renewal' => $new['booking_no'], 'from' => $p['from'], 'to' => $p['to'], 'grand_total' => $result['quote']->grandTotal], null, $actor->type, $actor->id);
        $req = $result['quote']->payableNow;
        $this->notifier->event($new, 'booking.extended', 'extension created', sprintf(
            'We extended booking %s: %s → %s at the current rate. Pay %s at the front desk to confirm the extension.',
            $booking['booking_no'],
            format_date($p['from'], 'd M Y'),
            format_date($p['to'], 'd M Y'),
            money($req, fmod($req, 1.0) ? 2 : 0),
        ));
        return $result;
    }

    /**
     * Add-ons of the original booking still offered (facility id => qty).
     *
     * @return array<int, int>
     */
    public function addons(int $bookingId): array
    {
        $out = [];
        foreach ($this->db->select(
            "SELECT bf.facility_id, bf.qty FROM booking_facilities bf JOIN facilities f ON f.id = bf.facility_id WHERE bf.booking_id = ? AND f.is_active = 1 AND f.kind = 'addon' AND f.unit <> 'hour'",
            [$bookingId],
        ) as $r) {
            $out[(int) $r['facility_id']] = max(1, (int) $r['qty']);
        }
        return $out;
    }

    /**
     * The follow-on booking of a booking, if any (not cancelled/rejected).
     *
     * @return array<string, mixed>|null
     */
    public function renewalOf(int $bookingId): ?array
    {
        return $this->db->first("SELECT * FROM bookings WHERE renewed_from_id = ? AND status NOT IN ('cancelled', 'rejected') ORDER BY id DESC LIMIT 1", [$bookingId]);
    }

    /**
     * Explorer preselection for a visitor renewal: floor, dates and live seat ids of the booking.
     *
     * @param array<string, mixed> $booking
     * @return array{booking_no: string, floor: ?string, from: string, to: string, seat_ids: list<int>, category: string, codes: list<string>}
     */
    public function explorerPreset(array $booking): array
    {
        $keys = array_map('intval', $this->db->column('SELECT seat_key FROM booking_seats WHERE booking_id = ? AND released_at IS NULL', [(int) $booking['id']]));
        if ($keys === []) {
            $keys = array_map('intval', $this->db->column('SELECT seat_key FROM booking_seats WHERE booking_id = ? AND transferred_to_id IS NULL', [(int) $booking['id']]));
        }
        $live = $this->availability->publishedByKey($keys);
        try {
            $category = (string) $this->db->scalar('SELECT code FROM seat_categories WHERE id = ?', [(int) $booking['seat_category_id']]);
        } catch (Throwable) {
            $category = '';
        }
        return [
            'booking_no' => (string) $booking['booking_no'],
            'floor' => $live !== [] ? (string) array_values($live)[0]['floor_slug'] : null,
            'from' => $this->startDate($booking),
            'to' => $this->defaultEnd($booking),
            'seat_ids' => array_values(array_map(static fn (array $l) => (int) $l['id'], $live)),
            'codes' => array_values(array_map(static fn (array $l) => (string) $l['code'], $live)),
            'category' => $category,
        ];
    }
}
