<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Core\Database;
use App\Enums\SeatCategory;
use DateTimeImmutable;

/**
 * Occupancy maths for reports, dashboards and the floor heat-maps (spec 6.5).
 *
 *   capacity   seat-days: every bookable UNIT of the published layouts (rows without a parent) × its seats
 *              (chairs of a cabin / room, else 1) × days in the range — days on which the unit is blocked or under
 *              maintenance (seats.status + status_from/to) are left out
 *   occupied   seat-days covered by booking_seats of COMMITTED bookings (approved, confirmed, active, completed —
 *              requests, cancellations and rejections do not count), matched by seat_key so bookings made on older
 *              layout versions still land on today's seat; a released row (handover / early exit) counts until the
 *              day it was cut to. Hourly units (conference room) count booked hours / opening hours of the day.
 *   pct        occupied / capacity (0..1)
 *
 * Everything is computed per unit per day in PHP (a centre has ~150 units; a year is 365 days), which keeps the
 * blocked-day and hourly rules in one place.
 */
final class OccupancyService
{
    public const COMMITTED = ['approved', 'confirmed', 'active', 'completed'];

    /** @var list<array<string, mixed>>|null */
    private ?array $units = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Published bookable units with floor + category.
     *
     * @return list<array{key: int, id: int, code: string, label: string, kind: string, seats: int, floor_id: int, floor: string, floor_slug: string,
     *                    category: string, category_name: string, colour: string, status: string, status_from: ?string, status_to: ?string}>
     */
    public function units(): array
    {
        if ($this->units !== null) {
            return $this->units;
        }
        $rows = $this->db->select(
            "SELECT s.id, s.seat_key, s.code, s.label, s.kind, s.capacity, s.status, s.status_from, s.status_to,
                    (SELECT COUNT(*) FROM seats ch WHERE ch.parent_id = s.id) AS chairs,
                    f.id AS floor_id, f.name AS floor, f.slug AS floor_slug, f.sort_order AS floor_order,
                    sc.code AS category, sc.name AS category_name, sc.colour, sc.sort_order AS category_order
             FROM seats s
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             JOIN floors f ON f.id = lv.floor_id
             JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE s.parent_id IS NULL
             ORDER BY f.sort_order, f.level, sc.sort_order, s.code",
        );
        $this->units = array_map(static fn (array $r) => [
            'key' => (int) $r['seat_key'], 'id' => (int) $r['id'], 'code' => (string) $r['code'], 'label' => (string) ($r['label'] ?? $r['code']),
            'kind' => (string) $r['kind'], 'seats' => max(1, (int) $r['chairs'] ?: (int) $r['capacity']),
            'floor_id' => (int) $r['floor_id'], 'floor' => (string) $r['floor'], 'floor_slug' => (string) $r['floor_slug'],
            'category' => (string) $r['category'], 'category_name' => (string) $r['category_name'], 'colour' => (string) $r['colour'],
            'status' => (string) $r['status'], 'status_from' => $r['status_from'], 'status_to' => $r['status_to'],
        ], $rows);
        return $this->units;
    }

    /**
     * Per-unit and per-day occupancy for a date range.
     *
     * @return array{units: list<array<string, mixed>>, daily: list<array{date: string, capacity: float, occupied: float, pct: float}>, capacity: float, occupied: float, pct: float, days: int}
     */
    public function compute(string $from, string $to, ?string $floorSlug = null, ?string $category = null): array
    {
        $units = array_values(array_filter($this->units(), static fn (array $u) => ($floorSlug === null || $floorSlug === '' || $u['floor_slug'] === $floorSlug)
            && ($category === null || $category === '' || $u['category'] === $category)));
        $dates = [];
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $dates[] = $d->format('Y-m-d');
        }
        $occ = $this->occupiedFractions($from, $to); // unitKey => date => fraction 0..1
        $dailyCap = array_fill_keys($dates, 0.0);
        $dailyOcc = array_fill_keys($dates, 0.0);
        $out = [];
        foreach ($units as $u) {
            $cap = 0.0;
            $used = 0.0;
            $blockedDays = 0;
            foreach ($dates as $date) {
                if ($this->blockedOn($u, $date)) {
                    $blockedDays++;
                    continue;
                }
                $frac = min(1.0, $occ[$u['key']][$date] ?? 0.0);
                $cap += $u['seats'];
                $used += $frac * $u['seats'];
                $dailyCap[$date] += $u['seats'];
                $dailyOcc[$date] += $frac * $u['seats'];
            }
            $out[] = $u + [
                'capacity' => round($cap, 2),
                'occupied' => round($used, 2),
                'pct' => $cap > 0 ? round($used / $cap, 4) : 0.0,
                'blocked_days' => $blockedDays,
                'blocked' => $blockedDays === count($dates),
            ];
        }
        $daily = [];
        foreach ($dates as $date) {
            $daily[] = ['date' => $date, 'capacity' => round($dailyCap[$date], 2), 'occupied' => round($dailyOcc[$date], 2), 'pct' => $dailyCap[$date] > 0 ? round($dailyOcc[$date] / $dailyCap[$date], 4) : 0.0];
        }
        $capacity = array_sum(array_column($out, 'capacity'));
        $occupied = array_sum(array_column($out, 'occupied'));
        return [
            'units' => $out,
            'daily' => $daily,
            'capacity' => round($capacity, 2),
            'occupied' => round($occupied, 2),
            'pct' => $capacity > 0 ? round($occupied / $capacity, 4) : 0.0,
            'days' => count($dates),
        ];
    }

    /**
     * Group computed units by floor, category or floor + category.
     *
     * @param list<array<string, mixed>> $units compute()['units']
     * @return list<array<string, mixed>>
     */
    public static function group(array $units, string $by): array
    {
        $groups = [];
        foreach ($units as $u) {
            $key = match ($by) {
                'floor' => (string) $u['floor'],
                'category' => (string) $u['category'],
                default => $u['floor'] . '|' . $u['category'],
            };
            $groups[$key] ??= ['floor' => $by === 'category' ? 'All floors' : $u['floor'], 'category' => $by === 'floor' ? 'All space types' : $u['category_name'],
                'category_code' => $by === 'floor' ? '' : $u['category'], 'colour' => $u['colour'], 'units' => 0, 'seats' => 0, 'blocked' => 0, 'capacity' => 0.0, 'occupied' => 0.0];
            $groups[$key]['units']++;
            $groups[$key]['seats'] += (int) $u['seats'];
            $groups[$key]['blocked'] += !empty($u['blocked']) ? 1 : 0;
            $groups[$key]['capacity'] += (float) $u['capacity'];
            $groups[$key]['occupied'] += (float) $u['occupied'];
        }
        return array_values(array_map(static fn (array $g) => $g + [
            'free' => round($g['capacity'] - $g['occupied'], 2),
            'pct' => $g['capacity'] > 0 ? round($g['occupied'] / $g['capacity'], 4) : 0.0,
        ], $groups));
    }

    /**
     * Occupied fraction per unit key per date (hourly bookings: hours / opening hours).
     *
     * @return array<int, array<string, float>>
     */
    private function occupiedFractions(string $from, string $to): array
    {
        $unitOfKey = [];
        foreach ($this->db->select(
            "SELECT s.seat_key, COALESCE(p.seat_key, s.seat_key) AS unit_key FROM seats s
             JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             LEFT JOIN seats p ON p.id = s.parent_id",
        ) as $r) {
            $unitOfKey[(int) $r['seat_key']] = (int) $r['unit_key'];
        }
        $st = implode(',', array_fill(0, count(self::COMMITTED), '?'));
        $open = max(1, (int) setting('conference_close_hour', 20) - (int) setting('conference_open_hour', 8));
        $out = [];
        foreach ($this->db->select(
            "SELECT bs.seat_key, bs.start_date, bs.end_date, bs.start_time, bs.end_time
             FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
             WHERE b.status IN ({$st}) AND bs.start_date <= ? AND bs.end_date >= ? AND bs.end_date >= bs.start_date",
            [...self::COMMITTED, $to, $from],
        ) as $r) {
            $unit = $unitOfKey[(int) $r['seat_key']] ?? null;
            if ($unit === null) {
                continue; // seat no longer in any published layout
            }
            $frac = 1.0;
            if ($r['start_time'] !== null && $r['end_time'] !== null) {
                $frac = min(1.0, max(0.0, (strtotime('1970-01-01 ' . $r['end_time'] . ' UTC') - strtotime('1970-01-01 ' . $r['start_time'] . ' UTC')) / 3600 / $open));
            }
            $d = new DateTimeImmutable(max($from, (string) $r['start_date']));
            $end = new DateTimeImmutable(min($to, (string) $r['end_date']));
            for (; $d <= $end; $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                $out[$unit][$k] = ($out[$unit][$k] ?? 0.0) + $frac;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $u */
    private function blockedOn(array $u, string $date): bool
    {
        if ($u['status'] === 'available') {
            return false;
        }
        return ($u['status_from'] === null || $u['status_from'] <= $date) && ($u['status_to'] === null || $u['status_to'] >= $date);
    }

    /** @return array<string, string> category code => name, in display order */
    public function categories(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT code, name FROM seat_categories ORDER BY sort_order') as $r) {
            $out[(string) $r['code']] = (string) $r['name'];
        }
        return $out;
    }

    /** @return array<string, string> floor slug => name */
    public function floors(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT slug, name FROM floors ORDER BY sort_order, level') as $r) {
            $out[(string) $r['slug']] = (string) $r['name'];
        }
        return $out;
    }

    public static function isHourly(string $category): bool
    {
        return SeatCategory::tryFrom($category)?->hourlyOnly() ?? false;
    }
}
