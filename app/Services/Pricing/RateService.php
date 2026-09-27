<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Core\Database;
use App\Enums\BillingUnit;
use App\Enums\RateScope;
use App\Services\AuditLog;
use App\Services\Layout\LayoutException;
use App\Services\Space\AvailabilityService;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Writes effective-dated rates (spec 5.4 "Set price", 5.5). The resolver (PriceResolver) reads them.
 *
 * Rules
 *   - Amounts are never edited. A change is a NEW row effective from a date (today or later); the previous
 *     open row of the same scope + unit is closed the day before (effective_to).
 *   - Rows starting on/after the new date are superseded: deleted if they never priced a booking, otherwise
 *     the change is refused ("pick a later date") — a rate that priced a booking is immutable.
 *   - A rate "priced a booking" when a booking created after the rate row, starting inside the rate's range,
 *     has a seat that resolves to the rate's scope (category / zone key / seat key).
 *   - Scopes: category = seat_categories.id, zone = zones.zone_key, seat = seats.seat_key (stable across
 *     layout versions). Every write is audit-logged (rate.create / rate.close / rate.supersede / rate.clear).
 */
final class RateService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Set a rate for one or more scope ids (bulk edit) from $from.
     *
     * @param list<int> $scopeIds
     * @return list<int> new rate ids
     */
    public function set(RateScope $scope, array $scopeIds, BillingUnit $unit, float $amount, float $gstRate, string $from, ?int $staffId = null, ?string $note = null): array
    {
        $this->assertDate($from);
        if ($amount <= 0 || $amount > 10_000_000) {
            throw new LayoutException('Enter an amount greater than 0.');
        }
        if ($gstRate < 0 || $gstRate > 28) {
            throw new LayoutException('GST rate must be between 0 and 28 %.');
        }
        $scopeIds = array_values(array_unique(array_map('intval', $scopeIds)));
        if ($scopeIds === []) {
            throw new LayoutException('Nothing selected to price.');
        }
        return $this->db->transaction(function (Database $db) use ($scope, $scopeIds, $unit, $amount, $gstRate, $from, $staffId, $note): array {
            $ids = [];
            foreach ($scopeIds as $scopeId) {
                $this->closeFrom($scope, $scopeId, $unit, $from);
                $id = $db->insert('rates', [
                    'scope' => $scope->value, 'scope_id' => $scopeId, 'unit' => $unit->value,
                    'amount' => round($amount, 2), 'gst_rate' => round($gstRate, 2),
                    'effective_from' => $from, 'effective_to' => null,
                    'notes' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                    'created_by' => $staffId,
                ]);
                $this->audit->record('rate.create', 'rate', $id, null, [
                    'scope' => $scope->value, 'scope_id' => $scopeId, 'unit' => $unit->value, 'amount' => round($amount, 2),
                    'gst_rate' => round($gstRate, 2), 'effective_from' => $from,
                ], $note);
                $ids[] = $id;
            }
            return $ids;
        });
    }

    /**
     * Remove a seat/zone override from $from (the zone / category rate applies again). $unit = null clears all units.
     *
     * @param list<int> $scopeIds
     */
    public function clear(RateScope $scope, array $scopeIds, ?BillingUnit $unit, string $from): int
    {
        if ($scope === RateScope::Category) {
            throw new LayoutException('A category base rate cannot be cleared — set a new rate instead.');
        }
        $this->assertDate($from);
        return $this->db->transaction(function () use ($scope, $scopeIds, $unit, $from): int {
            $n = 0;
            foreach (array_unique(array_map('intval', $scopeIds)) as $scopeId) {
                foreach ($unit !== null ? [$unit] : BillingUnit::cases() as $u) {
                    $n += $this->closeFrom($scope, $scopeId, $u, $from, 'rate.clear');
                }
            }
            return $n;
        });
    }

    /**
     * Close/supersede every row of scope+unit that is effective on or after $from. Returns rows touched.
     */
    private function closeFrom(RateScope $scope, int $scopeId, BillingUnit $unit, string $from, string $closeAction = 'rate.close'): int
    {
        $prevDay = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $n = 0;
        foreach ($this->db->select(
            'SELECT * FROM rates WHERE scope = ? AND scope_id = ? AND unit = ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from FOR UPDATE',
            [$scope->value, $scopeId, $unit->value, $from],
        ) as $r) {
            if ($r['effective_from'] >= $from) {
                if ($this->pricedBookings($r)) {
                    throw new LayoutException(sprintf(
                        'The %s rate of %s from %s has already priced bookings and cannot be replaced. Choose a date after %s.',
                        $unit->value,
                        money((float) $r['amount']),
                        format_date((string) $r['effective_from']),
                        format_date((string) $r['effective_from']),
                    ));
                }
                $this->db->execute('DELETE FROM rates WHERE id = ?', [(int) $r['id']]);
                $this->audit->record('rate.supersede', 'rate', (int) $r['id'], $this->auditRow($r), null);
            } else {
                $this->db->execute('UPDATE rates SET effective_to = ? WHERE id = ?', [$prevDay, (int) $r['id']]);
                $this->audit->record($closeAction, 'rate', (int) $r['id'], ['effective_to' => $r['effective_to']], ['effective_to' => $prevDay]);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Has this rate row priced any booking (see class doc)?
     *
     * @param array<string, mixed> $rate
     */
    public function pricedBookings(array $rate): bool
    {
        $st = AvailabilityService::activeStatuses();
        $st[] = 'completed';
        $in = implode(',', array_fill(0, count($st), '?'));
        $range = 'b.start_date >= ? AND (? IS NULL OR b.start_date <= ?) AND b.created_at >= ?';
        $bind = [$rate['effective_from'], $rate['effective_to'], $rate['effective_to'], $rate['created_at']];
        $sql = match ((string) $rate['scope']) {
            'category' => "SELECT 1 FROM bookings b WHERE b.status IN ({$in}) AND b.seat_category_id = ? AND {$range} LIMIT 1",
            'seat' => "SELECT 1 FROM bookings b JOIN booking_seats bs ON bs.booking_id = b.id WHERE b.status IN ({$in}) AND bs.seat_key = ? AND {$range} LIMIT 1",
            default => "SELECT 1 FROM bookings b JOIN booking_seats bs ON bs.booking_id = b.id JOIN seats s ON s.id = bs.seat_id JOIN zones z ON z.id = s.zone_id
                        WHERE b.status IN ({$in}) AND z.zone_key = ? AND {$range} LIMIT 1",
        };
        return $this->db->scalar($sql, [...$st, (int) $rate['scope_id'], ...$bind]) !== null;
    }

    /**
     * Rate history of one scope (newest first) with "locked" flags and the author.
     *
     * @return list<array<string, mixed>>
     */
    public function history(RateScope $scope, int $scopeId): array
    {
        $rows = $this->db->select(
            'SELECT r.*, u.name AS created_by_name FROM rates r LEFT JOIN staff_users u ON u.id = r.created_by
             WHERE r.scope = ? AND r.scope_id = ? ORDER BY r.unit, r.effective_from DESC, r.id DESC',
            [$scope->value, $scopeId],
        );
        $today = $this->clock->today();
        foreach ($rows as &$r) {
            $r['locked'] = $this->pricedBookings($r);
            $r['state'] = $r['effective_from'] > $today ? 'scheduled' : ($r['effective_to'] !== null && $r['effective_to'] < $today ? 'ended' : 'current');
        }
        return $rows;
    }

    /**
     * Rate rows (all units) currently set on seat/zone keys, for the inspector.
     *
     * @param list<int> $keys
     * @return array<int, array<string, array<string, mixed>>> key => unit => row
     */
    public function current(RateScope $scope, array $keys, ?string $onDate = null): array
    {
        if ($keys === []) {
            return [];
        }
        $onDate ??= $this->clock->today();
        $in = implode(',', array_fill(0, count($keys), '?'));
        $out = [];
        foreach ($this->db->select(
            "SELECT * FROM rates WHERE scope = ? AND scope_id IN ({$in}) AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from, id",
            [$scope->value, ...array_map('intval', $keys), $onDate, $onDate],
        ) as $r) {
            $out[(int) $r['scope_id']][(string) $r['unit']] = $r;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function auditRow(array $r): array
    {
        return array_intersect_key($r, array_flip(['scope', 'scope_id', 'unit', 'amount', 'gst_rate', 'effective_from', 'effective_to']));
    }

    private function assertDate(string $from): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || strtotime($from) === false) {
            throw new LayoutException('Enter a valid effective-from date.');
        }
        if ($from < $this->clock->today()) {
            throw new LayoutException('Rates take effect today or later — past prices stay as they were.');
        }
    }
}
