<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Core\Database;
use App\Enums\SeatCategory;
use App\Services\SettingsService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SpaceRuleException;

/**
 * Builds a Quote for a selection (spec 5.5): loads the units, resolves their rates on the start date
 * (PriceResolver: seat ?? zone ?? category), validates add-ons against stock for the period and
 * picks CGST+SGST vs IGST from the customer's GST state code, then delegates the maths to
 * QuoteCalculator.
 */
final class QuoteService
{
    public function __construct(
        private readonly Database $db,
        private readonly PriceResolver $prices,
        private readonly QuoteCalculator $calculator,
        private readonly SettingsService $settings,
    ) {
    }

    public function policy(): QuotePolicy
    {
        return QuotePolicy::fromSettings($this->settings);
    }

    /**
     * @param list<int> $seatIds units (a chair id is mapped to its cabin/room)
     * @param array<int|string, int|string> $addons facility id => qty
     * @param ?string $customerStateCode GST state code of the customer (null = intra-state)
     */
    public function quote(array $seatIds, BookingPeriod $period, array $addons = [], ?string $customerStateCode = null, ?int $ignoreBookingId = null): Quote
    {
        $units = $this->units($seatIds);
        $category = SeatCategory::from((string) $units[0]['category']);
        $rates = $this->prices->forSeats(array_map(static fn (array $u) => (int) $u['id'], $units), $period->from);
        $allowed = array_map(static fn ($u) => $u->value, $category->billingUnits());
        $seats = [];
        foreach ($units as $u) {
            $seats[] = [
                'id' => (int) $u['id'],
                'code' => (string) $u['code'],
                'label' => (string) ($u['label'] ?? $u['code']),
                'capacity' => (int) $u['capacity'],
                'rates' => array_intersect_key($rates[(int) $u['id']] ?? [], array_flip($allowed)),
            ];
        }
        $policy = $this->policy();
        return $this->calculator->calculate(
            $category,
            $period,
            $seats,
            $this->addonRows($addons, $period, $ignoreBookingId),
            $policy->isInterState($customerStateCode),
            $policy,
        );
    }

    /**
     * Chargeable add-ons that can be combined with a period (hour-billed ones only with hourly bookings),
     * with stock left for the period.
     *
     * @return list<array<string, mixed>>
     */
    public function addonCatalog(BookingPeriod $period, ?int $ignoreBookingId = null): array
    {
        $used = $this->stockUsed($period, $ignoreBookingId);
        $out = [];
        foreach ($this->db->select("SELECT * FROM facilities WHERE kind = 'addon' AND is_active = 1 ORDER BY sort_order") as $f) {
            $unit = (string) $f['unit'];
            $fits = $period->isHourly() ? in_array($unit, ['hour', 'use'], true) : in_array($unit, ['month', 'day', 'use'], true);
            if (!$fits) {
                continue;
            }
            $stock = $f['stock_qty'] !== null ? (int) $f['stock_qty'] : null;
            $out[] = [
                'id' => (int) $f['id'],
                'code' => (string) $f['code'],
                'name' => (string) $f['name'],
                'description' => (string) ($f['description'] ?? ''),
                'emoji' => $f['emoji'],
                'icon' => $f['icon'],
                'unit' => $unit,
                'price' => (float) $f['price'],
                'gst_rate' => (float) $f['gst_rate'],
                'stock' => $stock,
                'left' => $stock !== null ? max(0, $stock - ($used[(int) $f['id']] ?? 0)) : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<int|string, int|string> $addons
     * @return list<array{id: int, code: string, name: string, unit: ?string, price: float|string, gst_rate: float|string, qty: int, emoji?: ?string, icon?: ?string}>
     */
    private function addonRows(array $addons, BookingPeriod $period, ?int $ignoreBookingId): array
    {
        $wanted = [];
        foreach ($addons as $id => $qty) {
            if ((int) $qty > 0) {
                $wanted[(int) $id] = min(99, (int) $qty);
            }
        }
        if ($wanted === []) {
            return [];
        }
        $catalog = [];
        foreach ($this->addonCatalog($period, $ignoreBookingId) as $f) {
            $catalog[$f['id']] = $f;
        }
        $rows = [];
        foreach ($wanted as $id => $qty) {
            $f = $catalog[$id] ?? throw new SpaceRuleException('That add-on is not available for this booking.');
            if ($f['left'] !== null && $qty > $f['left']) {
                throw new SpaceRuleException($f['left'] === 0
                    ? sprintf('%s is sold out for these dates.', $f['name'])
                    : sprintf('Only %d × %s left for these dates.', $f['left'], $f['name']));
            }
            $rows[] = [
                'id' => $id, 'code' => $f['code'], 'name' => $f['name'], 'unit' => $f['unit'], 'price' => $f['price'],
                'gst_rate' => $f['gst_rate'], 'qty' => $qty, 'emoji' => $f['emoji'], 'icon' => $f['icon'],
            ];
        }
        return $rows;
    }

    /** @return array<int, int> facility id => qty booked in overlapping active bookings */
    private function stockUsed(BookingPeriod $period, ?int $ignoreBookingId): array
    {
        $st = implode(',', array_fill(0, count(AvailabilityService::activeStatuses()), '?'));
        $sql = "SELECT bf.facility_id, SUM(bf.qty) AS used FROM booking_facilities bf JOIN bookings b ON b.id = bf.booking_id
                WHERE b.status IN ({$st}) AND b.start_date <= ? AND b.end_date >= ?";
        $bind = [...AvailabilityService::activeStatuses(), $period->to, $period->from];
        if ($period->isHourly()) {
            $sql .= ' AND (b.start_time IS NULL OR (b.start_time < ? AND b.end_time > ?))';
            array_push($bind, $period->endTime, $period->startTime);
        }
        if ($ignoreBookingId !== null) {
            $sql .= ' AND b.id <> ?';
            $bind[] = $ignoreBookingId;
        }
        $out = [];
        foreach ($this->db->select($sql . ' GROUP BY bf.facility_id', $bind) as $r) {
            $out[(int) $r['facility_id']] = (int) $r['used'];
        }
        return $out;
    }

    /**
     * @param list<int> $seatIds
     * @return non-empty-list<array<string, mixed>>
     */
    private function units(array $seatIds): array
    {
        $seatIds = array_values(array_unique(array_map('intval', $seatIds)));
        if ($seatIds === []) {
            throw new SpaceRuleException('Choose at least one seat.');
        }
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        $unitIds = array_values(array_unique(array_map('intval', $this->db->column(
            "SELECT COALESCE(parent_id, id) FROM seats WHERE id IN ({$in})",
            $seatIds,
        ))));
        if ($unitIds === []) {
            throw new SpaceRuleException('Those seats no longer exist.');
        }
        $in = implode(',', array_fill(0, count($unitIds), '?'));
        $units = $this->db->select(
            "SELECT s.id, s.code, s.label, s.kind, s.capacity, sc.code AS category
             FROM seats s JOIN zones z ON z.id = s.zone_id JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE s.id IN ({$in}) ORDER BY s.code",
            $unitIds,
        );
        if ($units === [] || count(array_unique(array_column($units, 'category'))) !== 1) {
            throw new SpaceRuleException('Choose seats of one space type per booking.');
        }
        return $units;
    }
}
