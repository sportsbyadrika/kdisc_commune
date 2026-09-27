<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Core\Database;
use App\Enums\BillingUnit;

/**
 * Resolves the effective rate for a date (spec 5.5):
 *   seat price = seat override ?? zone rate ?? category base rate   (effective on the booking start date)
 *
 * Returns rows like ['amount' => 4000.0, 'gst_rate' => 18.0, 'scope' => 'category', 'rate_id' => 3].
 *
 * Seat and zone rates are keyed by the STABLE identities seats.seat_key / zones.zone_key (rates.scope_id),
 * so an override keeps applying when the Layout Designer publishes a new layout version.
 * Rates are never edited once created: RateService adds a new effective-dated row and closes the previous one.
 */
final class PriceResolver
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{amount: float, gst_rate: float, scope: string, rate_id: int}|null */
    public function forSeat(int $seatId, BillingUnit $unit, ?string $onDate = null): ?array
    {
        $seat = $this->db->first(
            'SELECT s.id, s.seat_key, z.zone_key, z.seat_category_id FROM seats s JOIN zones z ON z.id = s.zone_id WHERE s.id = ?',
            [$seatId],
        );
        if ($seat === null) {
            return null;
        }
        return $this->rate('seat', (int) $seat['seat_key'], $unit, $onDate)
            ?? $this->rate('zone', (int) $seat['zone_key'], $unit, $onDate)
            ?? ($seat['seat_category_id'] !== null ? $this->rate('category', (int) $seat['seat_category_id'], $unit, $onDate) : null);
    }

    /**
     * Bulk version of forSeat() for maps and quotes: every unit's effective rates in 2 queries.
     *
     * @param list<int> $seatIds
     * @return array<int, array<string, array{amount: float, gst_rate: float, scope: string, rate_id: int}>> seat id => unit => rate
     */
    public function forSeats(array $seatIds, ?string $onDate = null): array
    {
        if ($seatIds === []) {
            return [];
        }
        $onDate ??= date('Y-m-d');
        $seatIds = array_values($seatIds);
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        $seats = $this->db->select(
            "SELECT s.id, s.seat_key, z.zone_key, z.seat_category_id FROM seats s JOIN zones z ON z.id = s.zone_id WHERE s.id IN ({$in})",
            $seatIds,
        );
        $latest = [];
        foreach ($this->db->select(
            'SELECT id, scope, scope_id, unit, amount, gst_rate FROM rates
             WHERE effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from ASC, id ASC',
            [$onDate, $onDate],
        ) as $r) {
            $latest[$r['scope'] . ':' . $r['scope_id'] . ':' . $r['unit']] = [
                'amount' => (float) $r['amount'], 'gst_rate' => (float) $r['gst_rate'], 'scope' => (string) $r['scope'], 'rate_id' => (int) $r['id'],
            ];
        }
        $out = [];
        foreach ($seats as $s) {
            foreach (BillingUnit::cases() as $unit) {
                $rate = $latest['seat:' . $s['seat_key'] . ':' . $unit->value]
                    ?? $latest['zone:' . $s['zone_key'] . ':' . $unit->value]
                    ?? ($s['seat_category_id'] !== null ? ($latest['category:' . $s['seat_category_id'] . ':' . $unit->value] ?? null) : null);
                if ($rate !== null) {
                    $out[(int) $s['id']][$unit->value] = $rate;
                }
            }
        }
        return $out;
    }

    /** @return array{amount: float, gst_rate: float, scope: string, rate_id: int}|null */
    public function forCategory(int $categoryId, BillingUnit $unit, ?string $onDate = null): ?array
    {
        return $this->rate('category', $categoryId, $unit, $onDate);
    }

    /**
     * Current category rates for every unit, keyed by category id then unit.
     *
     * @return array<int, array<string, array{amount: float, gst_rate: float}>>
     */
    public function categoryRateCard(?string $onDate = null): array
    {
        $onDate ??= date('Y-m-d');
        $rows = $this->db->select(
            "SELECT r.scope_id, r.unit, r.amount, r.gst_rate FROM rates r
             WHERE r.scope = 'category' AND r.effective_from <= ? AND (r.effective_to IS NULL OR r.effective_to >= ?)
             ORDER BY r.effective_from ASC, r.id ASC",
            [$onDate, $onDate],
        );
        $card = [];
        foreach ($rows as $r) {
            // later effective_from overrides earlier
            $card[(int) $r['scope_id']][(string) $r['unit']] = ['amount' => (float) $r['amount'], 'gst_rate' => (float) $r['gst_rate']];
        }
        return $card;
    }

    /** @return array{amount: float, gst_rate: float, scope: string, rate_id: int}|null */
    private function rate(string $scope, int $scopeId, BillingUnit $unit, ?string $onDate): ?array
    {
        $onDate ??= date('Y-m-d');
        $row = $this->db->first(
            'SELECT id, amount, gst_rate FROM rates
             WHERE scope = ? AND scope_id = ? AND unit = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from DESC, id DESC LIMIT 1',
            [$scope, $scopeId, $unit->value, $onDate, $onDate],
        );
        return $row === null ? null : [
            'amount' => (float) $row['amount'],
            'gst_rate' => (float) $row['gst_rate'],
            'scope' => $scope,
            'rate_id' => (int) $row['id'],
        ];
    }
}
