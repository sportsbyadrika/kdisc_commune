<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Core\Database;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\SeatCategory;
use App\Services\Bookings\BookingService;
use App\Services\Imports\ImportColumn;
use App\Services\Imports\ImportContext;
use App\Services\Imports\Importer;
use App\Services\Pricing\QuoteService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Services\Space\SeatHoldService;
use App\Services\Space\SpaceRuleException;
use InvalidArgumentException;

/**
 * Reception bookings in bulk (status approved — a payment confirms them, exactly like a desk booking). Seats resolve
 * to units of the CURRENT published layouts by code (G-FX-04, G-CB-B) or stable seat_key; the period is checked by
 * SeatHoldService::assertPeriod (category rules), availability by AvailabilityService (and against earlier rows of
 * the file), the price by QuoteService — and the row is imported through BookingService::create(), the same
 * double-booking guard (row locks + re-check) as the Space Explorer.
 */
final class BookingsImporter extends Importer
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityService $availability,
        private readonly SeatHoldService $holds,
        private readonly QuoteService $quotes,
        private readonly BookingService $bookings,
    ) {
    }

    public function key(): string
    {
        return 'bookings';
    }

    public function label(): string
    {
        return 'Bookings';
    }

    public function description(): string
    {
        return 'Reception bookings (approved, awaiting payment) for registered visitors, by seat code and dates.';
    }

    public function icon(): string
    {
        return 'calendar-check';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('visitor', 'Visitor Unique ID', true, 'CMN-KTR-I-2026-00001', 'The Unique Visitor ID (the visitor must be registered and submitted).', type: 'id', width: 22),
            new ImportColumn('seats', 'Seat codes', true, 'G-FX-04, G-FX-05', 'Comma-separated seat / cabin / room codes of one space type, as on the seat map (e.g. G-DD-12, G-CB-B, G-CF-F).', width: 22),
            new ImportColumn('from', 'From date', true, '01-11-2026', 'dd-mm-yyyy — today or later. For the conference room: the date of the slot.', type: 'date', width: 12),
            new ImportColumn('to', 'To date', false, '30-11-2026', 'dd-mm-yyyy — last day (inclusive). Leave empty for a single day / conference slot.', type: 'date', width: 12),
            new ImportColumn('start_time', 'Start time', false, '', 'Conference room only — HH:MM on the hour, e.g. 10:00.', type: 'time', width: 10),
            new ImportColumn('end_time', 'End time', false, '', 'Conference room only — HH:MM, e.g. 13:00.', type: 'time', width: 10),
            new ImportColumn('addons', 'Add-ons', false, 'Personal locker', 'Optional: add-on names or codes, "Name x2" for a quantity, separated by ";".', width: 24),
            new ImportColumn('notes', 'Notes', false, '', 'Internal note on the booking.', width: 24),
        ];
    }

    /** @return list<string> */
    public function instructions(): array
    {
        return [
            'Each row creates ONE booking in status "Approved" (reception booking); it becomes Confirmed when the required payment is logged (Payments import or the booking page).',
            'Seats must be free for the whole period — seats taken by existing bookings, holds or earlier rows of this file are refused.',
            'Cabins and the conference room are booked as a whole unit; the conference room is booked by the hour between the opening hours.',
        ];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        $data = ['notes' => trim($row['notes'] ?? '')];
        $uid = strtoupper(trim($row['visitor'] ?? ''));
        $customer = $uid !== '' ? $this->db->first('SELECT * FROM customers WHERE unique_id = ?', [$uid]) : null;
        if ($uid === '') {
            $errors['visitor'] = 'Enter the Unique Visitor ID.';
        } elseif ($customer === null) {
            $errors['visitor'] = "No visitor with Unique ID {$uid}.";
        } elseif ($customer['kyc_status'] === 'rejected') {
            $errors['visitor'] = 'This visitor\'s KYC was rejected — fix the profile first.';
        }

        // seats → published units
        $codes = array_values(array_filter(array_map(static fn (string $c) => strtoupper(trim($c)), preg_split('/[,;\s]+/', $row['seats'] ?? '') ?: [])));
        $units = [];
        if ($codes === []) {
            $errors['seats'] = 'Enter at least one seat code.';
        }
        foreach ($codes as $code) {
            $s = $this->db->first(
                "SELECT s.id, s.seat_key, s.code, s.parent_id, p.code AS parent_code, sc.code AS category
                 FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
                 LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id LEFT JOIN seats p ON p.id = s.parent_id
                 WHERE s.code = ? OR (? REGEXP '^[0-9]+$' AND s.seat_key = ?) LIMIT 1",
                [$code, $code, ctype_digit($code) ? (int) $code : 0],
            );
            if ($s === null || $s['category'] === null) {
                $errors['seats'] ??= "Seat {$code} is not on the current seat map.";
            } elseif ($s['parent_id'] !== null) {
                $errors['seats'] ??= "{$code} is a chair of {$s['parent_code']} — book the whole unit {$s['parent_code']}.";
            } else {
                $units[(int) $s['id']] = $s;
            }
        }
        $cats = array_unique(array_column($units, 'category'));
        $category = count($cats) === 1 ? SeatCategory::tryFrom((string) $cats[0]) : null;
        if ($units !== [] && $category === null) {
            $errors['seats'] ??= 'All seats of one booking must be of the same space type.';
        }

        // period
        $period = null;
        try {
            $from = (string) ($row['from'] ?? '');
            $to = ($row['to'] ?? '') !== '' ? (string) $row['to'] : $from;
            if ($from === '') {
                $errors['from'] = 'Enter the start date (dd-mm-yyyy).';
            } elseif (($row['start_time'] ?? '') !== '' || ($row['end_time'] ?? '') !== '' || $category?->hourlyOnly()) {
                $period = BookingPeriod::fromInput(['from' => $from, 'to' => $from, 'start_time' => $row['start_time'] ?? '', 'end_time' => $row['end_time'] ?? ''], true);
            } else {
                $period = BookingPeriod::days($from, $to);
            }
        } catch (InvalidArgumentException $e) {
            $errors[($row['start_time'] ?? '') !== '' || ($row['end_time'] ?? '') !== '' ? 'start_time' : 'to'] = $e->getMessage();
        }

        $addons = [];
        foreach (array_filter(array_map('trim', explode(';', $row['addons'] ?? ''))) as $part) {
            $qty = 1;
            if (preg_match('/^(.*?)\s*[x×*]\s*(\d+)$/iu', $part, $m)) {
                [$part, $qty] = [trim($m[1]), (int) $m[2]];
            }
            $f = $this->db->first("SELECT id FROM facilities WHERE kind = 'addon' AND is_active = 1 AND (LOWER(name) = LOWER(?) OR code = ?)", [$part, strtoupper($part)]);
            if ($f === null) {
                $errors['addons'] ??= "Unknown add-on \"{$part}\".";
            } else {
                $addons[(int) $f['id']] = ($addons[(int) $f['id']] ?? 0) + max(1, $qty);
            }
        }

        $note = '';
        if ($errors === [] && $period !== null && $category !== null && $customer !== null) {
            try {
                $this->holds->assertPeriod($category, $period);
                if ($category->wholeUnitOnly() && count($units) > 1) {
                    throw new SpaceRuleException('Book one cabin / the conference room per row.');
                }
                foreach ($this->availability->seatStatuses(array_keys($units), $period) as $id => $status) {
                    if ($status !== AvailabilityService::AVAILABLE) {
                        throw new SpaceRuleException(sprintf('%s is %s for these dates.', $units[$id]['code'], $status === AvailabilityService::OCCUPIED ? 'already booked' : ($status === AvailabilityService::HELD ? 'held by someone' : 'not available')));
                    }
                }
                // earlier rows of this file
                foreach ($units as $u) {
                    foreach ($ctx->state['seats'][(int) $u['seat_key']] ?? [] as [$f, $t, $st, $et, $r]) {
                        $overlap = $f <= $period->to && $t >= $period->from && ($st === null || $period->startTime === null || ($st < $period->endTime && $et > $period->startTime));
                        if ($overlap) {
                            throw new SpaceRuleException(sprintf('%s is already booked by row %d of this file for overlapping dates.', $u['code'], $r));
                        }
                    }
                }
                $quote = $this->quotes->quote(array_keys($units), $period, $addons, (string) ($customer['state_code'] ?? ''));
                foreach ($units as $u) {
                    $ctx->state['seats'][(int) $u['seat_key']][] = [$period->from, $period->to, $period->startTime, $period->endTime, (int) ($row['_row'] ?? 0)];
                }
                $note = sprintf('%s · %s · %s (%s)', $customer['name'], $period->label(), money($quote->grandTotal, 2), $quote->paymentRule->value === 'advance' ? 'advance' : 'deposit ' . money($quote->depositAmount));
            } catch (SpaceRuleException $e) {
                $errors['seats'] = $e->getMessage();
            }
        }
        return [
            'data' => $data + ['customer_id' => (int) ($customer['id'] ?? 0), 'units' => array_keys($units), 'period' => $period?->toArray(), 'addons' => $addons],
            'errors' => $errors,
            'note' => $note,
        ];
    }

    public function import(array $data, ImportContext $ctx): string
    {
        $customer = (array) $this->db->first('SELECT * FROM customers WHERE id = ?', [$data['customer_id']]);
        $p = (array) $data['period'];
        $period = ($p['start_time'] ?? null) !== null ? BookingPeriod::hours((string) $p['from'], (string) $p['start_time'], (string) $p['end_time']) : BookingPeriod::days((string) $p['from'], (string) $p['to']);
        $r = $this->bookings->create(
            $customer,
            SeatHolder::staff($ctx->staffId(), 'import-' . $ctx->batchId, (int) $customer['id']),
            array_map('intval', (array) $data['units']),
            $period,
            (array) $data['addons'],
            BookingSource::Reception,
            ['status' => BookingStatus::Approved, 'created_by' => $ctx->staffId(), 'terms' => true, 'notes' => $data['notes'] !== '' ? mb_substr((string) $data['notes'], 0, 1000) : 'Bulk import #' . $ctx->batchId],
        );
        return (string) $r['booking']['booking_no'];
    }
}
