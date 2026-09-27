<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Enums\SeatCategory;
use App\Support\Clock;

/**
 * Seat availability for a period (spec 5.2, 8). Status of every seat row, in priority order:
 *
 *   blocked   seat (or its cabin/room) is blocked / under maintenance during the period
 *   occupied  an overlapping booking_seats row of a booking in an ACTIVE status
 *   held      an unexpired seat_holds row of someone else overlaps the period
 *   mine      an unexpired hold of the asking holder (same account/staff + session)
 *   available otherwise
 *
 * Bookable UNITS are rows without a parent: a flexi/dedicated chair, a whole cabin, the conference room.
 * Bookings and holds reference units; a cabin/room's chairs always report their parent's status
 * (booking a cabin occupies its three chairs). A booking or hold that references a chair of a
 * cabin makes the whole cabin unavailable too.
 *
 * Hourly-only units (the conference room) are checked by time only when the period is hourly; for a
 * day-range query they are "available" unless blocked (the slot picker shows the booked hours).
 *
 * Only PUBLISHED layout versions are considered (a draft can be previewed by passing its version id to
 * floorSeats()/floorStatuses()). Expired holds are ignored (and lazily purged by SeatHoldService).
 *
 * Seat identity across layout versions: bookings are matched by seats.seat_key (= booking_seats.seat_key),
 * never by the seat row id, so a booking made on an older layout version keeps occupying the same seat
 * after the Designer publishes a new version (see LayoutPublisher). Holds reference live rows and are
 * re-pointed on publish.
 */
final class AvailabilityService
{
    public const AVAILABLE = 'available';
    public const HELD = 'held';
    public const MINE = 'mine';
    public const OCCUPIED = 'occupied';
    public const BLOCKED = 'blocked';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /** @return list<string> booking statuses that occupy a seat */
    public static function activeStatuses(): array
    {
        return [BookingStatus::Requested->value, BookingStatus::Approved->value, BookingStatus::Confirmed->value, BookingStatus::Active->value];
    }

    /**
     * Seat rows of a floor's published layout with zone + category.
     *
     * @return list<array<string, mixed>>
     */
    public function floorSeats(int $floorId, ?int $versionId = null): array
    {
        $version = $versionId !== null ? 'lv.id = ?' : "lv.status = 'published'";
        return $this->db->select(
            "SELECT s.*, z.code AS zone_code, z.name AS zone_name, z.seat_category_id, sc.code AS category
             FROM seats s
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND {$version}
             LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE lv.floor_id = ?
             ORDER BY z.sort_order, s.parent_id IS NOT NULL, s.id",
            $versionId !== null ? [$versionId, $floorId] : [$floorId],
        );
    }

    /**
     * Status of every seat row on a floor.
     *
     * @return array<int, string> seat id => status
     */
    public function floorStatuses(int $floorId, BookingPeriod $period, ?SeatHolder $me = null, ?int $versionId = null): array
    {
        return $this->statusesFor($this->floorSeats($floorId, $versionId), $period, $me);
    }

    /**
     * Status of specific units (and their chairs) — used by holds and bookings, any floor.
     *
     * @param list<int> $seatIds
     * @return array<int, string>
     */
    public function seatStatuses(array $seatIds, BookingPeriod $period, ?SeatHolder $me = null, ?int $ignoreBookingId = null): array
    {
        if ($seatIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        $rows = $this->db->select(
            "SELECT s.*, z.seat_category_id, sc.code AS category
             FROM seats s
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE s.id IN ({$in}) OR s.parent_id IN ({$in})",
            [...$seatIds, ...$seatIds],
        );
        $all = $this->statusesFor($rows, $period, $me, $ignoreBookingId);
        $out = [];
        foreach ($seatIds as $id) {
            $out[$id] = $all[$id] ?? self::BLOCKED; // not in a published layout
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows seat rows incl. parent_id, status, status_from/to, category
     * @return array<int, string>
     */
    private function statusesFor(array $rows, BookingPeriod $period, ?SeatHolder $me, ?int $ignoreBookingId = null): array
    {
        if ($rows === []) {
            return [];
        }
        $parentOf = [];
        $hourly = [];
        $status = [];
        $idOfKey = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $idOfKey[(int) ($r['seat_key'] ?? $id)] = $id;
            $parentOf[$id] = $r['parent_id'] !== null ? (int) $r['parent_id'] : $id;
            if (SeatCategory::tryFrom((string) ($r['category'] ?? ''))?->hourlyOnly()) {
                $hourly[$id] = true;
            }
            $status[$id] = $this->isBlocked($r, $period) ? self::BLOCKED : self::AVAILABLE;
        }
        $unitOf = static fn (int $id): int => $parentOf[$id] ?? $id;

        // Which ids to check against bookings/holds: skip hourly units for day-range queries.
        $check = array_keys(array_filter($parentOf, static fn (int $unit, int $id) => $period->isHourly() || !isset($hourly[$id]), ARRAY_FILTER_USE_BOTH));
        // unit-level status (worst wins)
        $unit = [];
        $raise = static function (int $unitId, string $s) use (&$unit): void {
            $rank = [self::AVAILABLE => 0, self::MINE => 1, self::HELD => 2, self::OCCUPIED => 3, self::BLOCKED => 4];
            if (!isset($unit[$unitId]) || $rank[$s] > $rank[$unit[$unitId]]) {
                $unit[$unitId] = $s;
            }
        };
        foreach ($status as $id => $s) {
            if ($s === self::BLOCKED && $parentOf[$id] === $id) {
                $raise($id, self::BLOCKED);
            }
        }
        if ($check !== []) {
            $keyOf = array_flip($idOfKey);
            $keys = array_values(array_map(static fn (int $id): int => $keyOf[$id] ?? $id, $check));
            foreach ($this->overlappingBookingKeys($keys, $period, $ignoreBookingId) as $key) {
                if (isset($idOfKey[$key])) {
                    $raise($unitOf($idOfKey[$key]), self::OCCUPIED);
                }
            }
            foreach ($this->overlappingHolds($check, $period) as $h) {
                $mine = $me !== null && $h['holder_type'] === $me->type->value && (int) $h['holder_id'] === $me->id && $h['session_id'] === $me->sessionId;
                $raise($unitOf((int) $h['seat_id']), $mine ? self::MINE : self::HELD);
            }
        }
        foreach ($status as $id => $s) {
            $u = $unit[$parentOf[$id]] ?? self::AVAILABLE;
            // units take the unit status; a chair of a cabin shows its unit's status, or its own "blocked" (broken chair)
            $status[$id] = $parentOf[$id] === $id || $u !== self::AVAILABLE ? $u : $s;
        }
        return $status;
    }

    /** @param array<string, mixed> $seat */
    private function isBlocked(array $seat, BookingPeriod $period): bool
    {
        if (($seat['status'] ?? 'available') === 'available') {
            return false;
        }
        $from = $seat['status_from'] ?? null;
        $to = $seat['status_to'] ?? null;
        return ($from === null || $from <= $period->to) && ($to === null || $to >= $period->from);
    }

    /**
     * Seat ids (of any layout version) with an overlapping booking in an active status — matched by seat_key,
     * so bookings made on earlier layout versions count.
     *
     * @param list<int> $seatIds
     * @return list<int>
     */
    public function overlappingBookings(array $seatIds, BookingPeriod $period, ?int $ignoreBookingId = null): array
    {
        if ($seatIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        $idOfKey = [];
        foreach ($this->db->select("SELECT id, seat_key FROM seats WHERE id IN ({$in})", $seatIds) as $r) {
            $idOfKey[(int) $r['seat_key']] = (int) $r['id'];
        }
        $out = [];
        foreach ($this->overlappingBookingKeys(array_keys($idOfKey), $period, $ignoreBookingId) as $key) {
            $out[] = $idOfKey[$key];
        }
        return $out;
    }

    /**
     * seat_keys with an overlapping booking in an active status.
     *
     * @param list<int> $seatKeys
     * @return list<int>
     */
    public function overlappingBookingKeys(array $seatKeys, BookingPeriod $period, ?int $ignoreBookingId = null): array
    {
        if ($seatKeys === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($seatKeys), '?'));
        $st = implode(',', array_fill(0, count(self::activeStatuses()), '?'));
        $sql = "SELECT DISTINCT bs.seat_key FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
                WHERE bs.seat_key IN ({$in}) AND bs.released_at IS NULL AND b.status IN ({$st})
                  AND bs.start_date <= ? AND bs.end_date >= ?";
        $bind = [...$seatKeys, ...self::activeStatuses(), $period->to, $period->from];
        if ($period->isHourly()) {
            $sql .= ' AND (bs.start_time IS NULL OR (bs.start_time < ? AND bs.end_time > ?))';
            array_push($bind, $period->endTime, $period->startTime);
        }
        if ($ignoreBookingId !== null) {
            $sql .= ' AND b.id <> ?';
            $bind[] = $ignoreBookingId;
        }
        return array_map('intval', $this->db->column($sql, $bind));
    }

    /**
     * Unexpired holds overlapping the period.
     *
     * @param list<int> $seatIds
     * @return list<array<string, mixed>>
     */
    public function overlappingHolds(array $seatIds, BookingPeriod $period): array
    {
        if ($seatIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        return $this->db->select(
            "SELECT id, seat_id, holder_type, holder_id, session_id, customer_id, expires_at FROM seat_holds
             WHERE seat_id IN ({$in}) AND expires_at > ? AND start_at < ? AND end_at > ?",
            [...$seatIds, $this->clock->sql(), $period->endAt(), $period->startAt()],
        );
    }

    /**
     * Level-1 numbers: chairs and free chairs per floor and category for the period.
     *
     * @return array<int, array{chairs: int, free: int, by_category: array<string, array{chairs: int, free: int}>}>
     */
    public function floorSummary(BookingPeriod $period, ?SeatHolder $me = null): array
    {
        $out = [];
        foreach ($this->db->column('SELECT id FROM floors ORDER BY sort_order, level') as $floorId) {
            $floorId = (int) $floorId;
            $seats = $this->floorSeats($floorId);
            $statuses = $this->statusesFor($seats, $period, $me);
            $sum = ['chairs' => 0, 'free' => 0, 'by_category' => []];
            foreach ($seats as $s) {
                if ($s['kind'] !== 'seat' || $s['category'] === null) {
                    continue;
                }
                $code = (string) $s['category'];
                $free = in_array($statuses[(int) $s['id']] ?? '', [self::AVAILABLE, self::MINE], true) ? 1 : 0;
                $sum['chairs']++;
                $sum['free'] += $free;
                $sum['by_category'][$code] ??= ['chairs' => 0, 'free' => 0];
                $sum['by_category'][$code]['chairs']++;
                $sum['by_category'][$code]['free'] += $free;
            }
            $out[$floorId] = $sum;
        }
        return $out;
    }

    /**
     * Booked / held hour ranges of an hourly unit on a date (for the conference slot picker).
     *
     * @return list<array{start: string, end: string, status: string}>
     */
    public function hourlySlots(int $unitId, string $date, ?SeatHolder $me = null): array
    {
        $st = implode(',', array_fill(0, count(self::activeStatuses()), '?'));
        $out = [];
        foreach ($this->db->select(
            "SELECT bs.start_time, bs.end_time FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
             WHERE bs.seat_key = (SELECT seat_key FROM seats WHERE id = ?) AND bs.released_at IS NULL AND b.status IN ({$st}) AND bs.start_date <= ? AND bs.end_date >= ?
             ORDER BY bs.start_time",
            [$unitId, ...self::activeStatuses(), $date, $date],
        ) as $r) {
            $out[] = [
                'start' => $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 5) : '00:00',
                'end' => $r['end_time'] !== null ? substr((string) $r['end_time'], 0, 5) : '23:59',
                'status' => self::OCCUPIED,
            ];
        }
        foreach ($this->db->select(
            'SELECT start_at, end_at, holder_type, holder_id, session_id FROM seat_holds WHERE seat_id = ? AND expires_at > ? AND start_at < ? AND end_at > ?',
            [$unitId, $this->clock->sql(), $date . ' 23:59:59', $date . ' 00:00:00'],
        ) as $h) {
            $mine = $me !== null && $h['holder_type'] === $me->type->value && (int) $h['holder_id'] === $me->id && $h['session_id'] === $me->sessionId;
            $out[] = ['start' => substr((string) $h['start_at'], 11, 5), 'end' => substr((string) $h['end_at'], 11, 5), 'status' => $mine ? self::MINE : self::HELD];
        }
        return $out;
    }

    /**
     * Current occupants of a floor's units for the period (reception popover): unit id => booking + visitor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function occupants(int $floorId, BookingPeriod $period, ?int $versionId = null): array
    {
        $st = implode(',', array_fill(0, count(self::activeStatuses()), '?'));
        $version = $versionId !== null ? 'lv.id = ?' : "lv.status = 'published'";
        $sql = "SELECT s.id AS seat_id, b.id AS booking_id, b.booking_no, b.status, b.start_date, b.end_date, bs.start_time, bs.end_time,
                       c.name AS customer_name, c.unique_id
                FROM booking_seats bs
                JOIN bookings b ON b.id = bs.booking_id
                JOIN customers c ON c.id = b.customer_id
                JOIN seats s ON s.seat_key = bs.seat_key
                JOIN zones z ON z.id = s.zone_id
                JOIN layout_versions lv ON lv.id = z.layout_version_id AND {$version}
                WHERE lv.floor_id = ? AND bs.released_at IS NULL AND b.status IN ({$st})
                  AND bs.start_date <= ? AND bs.end_date >= ?";
        $bind = [...($versionId !== null ? [$versionId] : []), $floorId, ...self::activeStatuses(), $period->to, $period->from];
        if ($period->isHourly()) {
            $sql .= ' AND (bs.start_time IS NULL OR (bs.start_time < ? AND bs.end_time > ?))';
            array_push($bind, $period->endTime, $period->startTime);
        }
        $out = [];
        foreach ($this->db->select($sql . ' ORDER BY b.start_date', $bind) as $r) {
            $out[(int) $r['seat_id']] ??= $r;
        }
        return $out;
    }

    /**
     * Short fingerprint of a status map, so pollers can skip unchanged payloads.
     *
     * @param array<int, string> $statuses
     */
    public static function version(array $statuses): string
    {
        ksort($statuses);
        return substr(hash('sha256', (string) json_encode($statuses)), 0, 16);
    }
}
