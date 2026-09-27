<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Core\Database;
use App\Enums\SeatCategory;
use App\Services\AuditLog;
use App\Services\SettingsService;
use App\Support\Clock;

/**
 * Temporary seat holds while someone is choosing (spec 5.2): `setting('seat_hold_minutes')` (10) per
 * selection, one shared countdown per holder, renewable. Holds are rows in `seat_holds` keyed by holder
 * (visitor account or staff user) + session id; expired rows are ignored everywhere and purged lazily.
 *
 * Race safety: every hold runs in a transaction that locks the unit's `seats` rows (and its chairs)
 * with SELECT … FOR UPDATE before re-checking availability, so two people can never hold the same
 * unit for overlapping periods. BookingService takes the same locks when converting holds.
 *
 * Category rules (App\Enums\SeatCategory) are enforced here, server-side:
 *   - one category per selection (a booking has a single seat category)
 *   - cabin / conference room: whole unit only (a chair maps to its cabin), one unit per selection
 *   - conference room: hourly period only, whole hours within opening hours
 *   - flexi / dedicated: per seat, at most "seats needed" seats (≤ setting max_seats_per_booking)
 */
final class SeatHoldService
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityService $availability,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function holdMinutes(): int
    {
        return max(1, (int) $this->settings->get('seat_hold_minutes', 10));
    }

    /**
     * Hold seats for a holder. Seat ids may be chairs of a cabin/room (mapped to the whole unit).
     *
     * @param list<int> $seatIds
     * @return array{held: list<int>, failed: array<int, string>, expires_at: ?string, expires_in: int}
     */
    public function hold(SeatHolder $holder, array $seatIds, BookingPeriod $period, int $seatsNeeded = 1, bool $replace = false, ?string $overrideReason = null): array
    {
        $seatIds = array_values(array_unique(array_map('intval', $seatIds)));
        if ($seatIds === []) {
            throw new SpaceRuleException('Choose at least one seat.');
        }
        $override = $holder->canOverride && $overrideReason !== null && trim($overrideReason) !== '';
        if ($overrideReason !== null && trim($overrideReason) !== '' && !$holder->canOverride) {
            throw new SpaceRuleException('Only a Centre Manager can override a blocked or held seat.');
        }
        $maxSeats = max(1, (int) $this->settings->get('max_seats_per_booking', 40));
        $seatsNeeded = min(max(1, $seatsNeeded), $maxSeats);

        $units = $this->resolveUnits($seatIds);
        $category = $this->singleCategory($units);
        $this->assertPeriod($category, $period, $holder);
        $unitIds = array_map(static fn (array $u) => (int) $u['id'], $units);

        return $this->db->transaction(function (Database $db) use ($holder, $units, $unitIds, $category, $period, $seatsNeeded, $replace, $override, $overrideReason): array {
            // Lock the units and their chairs FIRST, so every read below (REPEATABLE READ snapshot) happens
            // after any concurrent hold/booking of the same seats has committed.
            $in = implode(',', array_fill(0, count($unitIds), '?'));
            $db->select("SELECT id FROM seats WHERE id IN ({$in}) OR parent_id IN ({$in}) ORDER BY id FOR UPDATE", [...$unitIds, ...$unitIds]);
            $db->execute("DELETE FROM seat_holds WHERE seat_id IN ({$in}) AND expires_at <= ?", [...$unitIds, $this->clock->sql()]);

            // Existing selection of this holder.
            if ($replace) {
                $this->release($holder);
            } else {
                // A selection always covers one period: holds for other dates are dropped.
                $db->execute(
                    'DELETE FROM seat_holds WHERE holder_type = ? AND holder_id = ? AND session_id = ? AND (start_at <> ? OR end_at <> ?)',
                    [$holder->type->value, $holder->id, $holder->sessionId, $period->startAt(), $period->endAt()],
                );
            }
            $existing = $this->current($holder);
            $existingIds = array_map(static fn (array $u) => (int) $u['seat_id'], $existing['units']);
            if ($existing['category'] !== null && $existing['category'] !== $category) {
                throw new SpaceRuleException(sprintf(
                    'Your selection is for %s. Clear it before choosing %s.',
                    $existing['category']->shortLabel() . ($existing['category']->multiSelect() ? ' seats' : ''),
                    mb_strtolower($category->label()),
                ));
            }
            $newUnits = array_values(array_filter($units, static fn (array $u) => !in_array((int) $u['id'], $existingIds, true)));
            $total = count($existingIds) + count($newUnits);
            if ($category->wholeUnitOnly() && $total > 1) {
                throw new SpaceRuleException($category === SeatCategory::Cabin
                    ? 'A booking covers one whole cabin. Book another cabin separately.'
                    : 'The conference room is booked as one room per request.');
            }
            if ($category->multiSelect() && $total > $seatsNeeded) {
                throw new SpaceRuleException(sprintf('You asked for %d seat%s. Increase “Seats needed” to pick more.', $seatsNeeded, $seatsNeeded === 1 ? '' : 's'));
            }

            $statuses = $this->availability->seatStatuses($unitIds, $period, $holder);
            $expires = $this->clock->now()->modify('+' . $this->holdMinutes() . ' minutes')->format('Y-m-d H:i:s');
            $held = [];
            $failed = [];
            foreach ($units as $u) {
                $id = (int) $u['id'];
                $status = $statuses[$id] ?? AvailabilityService::BLOCKED;
                if ($status === AvailabilityService::MINE) {
                    $held[] = $id;
                    continue;
                }
                $overridable = in_array($status, [AvailabilityService::HELD, AvailabilityService::BLOCKED], true);
                if ($status !== AvailabilityService::AVAILABLE && !($override && $overridable)) {
                    $failed[$id] = match ($status) {
                        AvailabilityService::OCCUPIED => sprintf('%s is already booked for these dates.', $u['code']),
                        AvailabilityService::HELD => sprintf('%s is being held by someone else right now.', $u['code']),
                        default => sprintf('%s is not available (blocked or under maintenance).', $u['code']),
                    };
                    continue;
                }
                if ($status !== AvailabilityService::AVAILABLE) {
                    if ($status === AvailabilityService::HELD) {
                        $db->execute('DELETE FROM seat_holds WHERE seat_id = ?', [$id]);
                    }
                    $this->audit->record('seat.override', 'seat', $id, ['status' => $status], ['held_for_customer' => $holder->customerId], (string) $overrideReason);
                }
                $db->insert('seat_holds', [
                    'seat_id' => $id,
                    'holder_type' => $holder->type->value,
                    'holder_id' => $holder->id,
                    'customer_id' => $holder->customerId,
                    'session_id' => $holder->sessionId,
                    'start_at' => $period->startAt(),
                    'end_at' => $period->endAt(),
                    'expires_at' => $expires,
                ]);
                $held[] = $id;
            }
            if ($held !== []) {
                // One countdown for the whole selection.
                $db->execute(
                    'UPDATE seat_holds SET expires_at = ? WHERE holder_type = ? AND holder_id = ? AND session_id = ? AND expires_at > ?',
                    [$expires, $holder->type->value, $holder->id, $holder->sessionId, $this->clock->sql()],
                );
            }
            $after = $this->current($holder);
            return ['held' => $held, 'failed' => $failed, 'expires_at' => $after['expires_at'], 'expires_in' => $after['expires_in']];
        });
    }

    /** Release one unit (a chair id releases its cabin) or, with null, the whole selection. */
    public function release(SeatHolder $holder, ?int $seatId = null): int
    {
        $sql = 'DELETE FROM seat_holds WHERE holder_type = ? AND holder_id = ? AND session_id = ?';
        $bind = [$holder->type->value, $holder->id, $holder->sessionId];
        if ($seatId !== null) {
            $parent = $this->db->scalar('SELECT parent_id FROM seats WHERE id = ?', [$seatId]);
            $sql .= ' AND seat_id = ?';
            $bind[] = $parent !== null ? (int) $parent : $seatId;
        }
        return $this->db->execute($sql, $bind);
    }

    /** Extend the holder's unexpired holds by another hold period. Returns the new expiry, or null when nothing is held. */
    public function renew(SeatHolder $holder): ?string
    {
        $expires = $this->clock->now()->modify('+' . $this->holdMinutes() . ' minutes')->format('Y-m-d H:i:s');
        $n = $this->db->execute(
            'UPDATE seat_holds SET expires_at = ? WHERE holder_type = ? AND holder_id = ? AND session_id = ? AND expires_at > ?',
            [$expires, $holder->type->value, $holder->id, $holder->sessionId, $this->clock->sql()],
        );
        return $n > 0 ? $expires : null;
    }

    /**
     * The holder's current (unexpired) selection.
     *
     * @return array{units: list<array<string, mixed>>, category: ?SeatCategory, period: ?BookingPeriod, expires_at: ?string, expires_in: int}
     */
    public function current(SeatHolder $holder): array
    {
        $rows = $this->db->select(
            "SELECT h.id AS hold_id, h.seat_id, h.start_at, h.end_at, h.expires_at, h.customer_id,
                    s.code, s.label, s.kind, s.capacity, s.zone_id, z.name AS zone_name, z.seat_category_id, sc.code AS category, f.name AS floor_name, f.slug AS floor_slug
             FROM seat_holds h
             JOIN seats s ON s.id = h.seat_id
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id
             JOIN floors f ON f.id = lv.floor_id
             LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE h.holder_type = ? AND h.holder_id = ? AND h.session_id = ? AND h.expires_at > ?
             ORDER BY s.code",
            [$holder->type->value, $holder->id, $holder->sessionId, $this->clock->sql()],
        );
        if ($rows === []) {
            return ['units' => [], 'category' => null, 'period' => null, 'expires_at' => null, 'expires_in' => 0];
        }
        $first = $rows[0];
        $start = (string) $first['start_at'];
        $end = (string) $first['end_at'];
        $period = substr($start, 11) === '00:00:00' && substr($end, 11) === '00:00:00'
            ? BookingPeriod::days(substr($start, 0, 10), (new \DateTimeImmutable(substr($end, 0, 10)))->modify('-1 day')->format('Y-m-d'))
            : BookingPeriod::hours(substr($start, 0, 10), substr($start, 11, 5), substr($end, 11, 5));
        $expiresAt = (string) min(array_column($rows, 'expires_at'));
        return [
            'units' => $rows,
            'category' => SeatCategory::tryFrom((string) $first['category']),
            'period' => $period,
            'expires_at' => $expiresAt,
            'expires_in' => max(0, strtotime($expiresAt) - $this->clock->now()->getTimestamp()),
        ];
    }

    /**
     * The selection as sent to the explorer (drawer chips, countdown), or null when nothing is held.
     *
     * @return array<string, mixed>|null
     */
    public function selectionPayload(SeatHolder $holder): ?array
    {
        $cur = $this->current($holder);
        if ($cur['units'] === []) {
            return null;
        }
        return [
            'category' => $cur['category']?->value,
            'period' => $cur['period']?->toArray(),
            'expires_in' => $cur['expires_in'],
            'hold_minutes' => $this->holdMinutes(),
            'units' => array_map(static fn (array $u) => [
                'id' => (int) $u['seat_id'], 'code' => (string) $u['code'], 'label' => (string) ($u['label'] ?? $u['code']), 'kind' => (string) $u['kind'],
                'capacity' => (int) $u['capacity'], 'zone' => (string) $u['zone_name'], 'floor' => (string) $u['floor_name'], 'floor_slug' => (string) $u['floor_slug'],
            ], $cur['units']),
        ];
    }

    /** Delete expired holds (called lazily; safe to run from cron). */
    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM seat_holds WHERE expires_at <= ?', [$this->clock->sql()]);
    }

    /**
     * Map requested ids to bookable units of a published layout.
     *
     * @param list<int> $seatIds
     * @return list<array<string, mixed>>
     */
    private function resolveUnits(array $seatIds): array
    {
        $in = implode(',', array_fill(0, count($seatIds), '?'));
        $rows = $this->db->select(
            "SELECT COALESCE(s.parent_id, s.id) AS unit_id FROM seats s
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             WHERE s.id IN ({$in})",
            $seatIds,
        );
        if (count($rows) !== count($seatIds)) {
            throw new SpaceRuleException('One of the chosen seats no longer exists. Please reload the map.');
        }
        $unitIds = array_values(array_unique(array_map(static fn (array $r) => (int) $r['unit_id'], $rows)));
        $in = implode(',', array_fill(0, count($unitIds), '?'));
        return $this->db->select(
            "SELECT s.id, s.code, s.kind, s.capacity, s.parent_id, sc.code AS category
             FROM seats s JOIN zones z ON z.id = s.zone_id LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE s.id IN ({$in}) ORDER BY s.id",
            $unitIds,
        );
    }

    /** @param list<array<string, mixed>> $units */
    private function singleCategory(array $units): SeatCategory
    {
        $cats = array_values(array_unique(array_map(static fn (array $u) => (string) $u['category'], $units)));
        if (count($cats) !== 1 || SeatCategory::tryFrom($cats[0]) === null) {
            throw new SpaceRuleException('Choose seats of one space type per booking.');
        }
        $category = SeatCategory::from($cats[0]);
        foreach ($units as $u) {
            if ($category->wholeUnitOnly() && $u['kind'] === 'seat') {
                throw new SpaceRuleException('Cabins and the conference room are booked as a whole.');
            }
        }
        return $category;
    }

    /** Period rules shared by holds and bookings. */
    public function assertPeriod(SeatCategory $category, BookingPeriod $period, ?SeatHolder $holder = null): void
    {
        $today = $this->clock->today();
        if ($period->from < $today) {
            throw new SpaceRuleException('The start date is in the past.');
        }
        if ($category->hourlyOnly()) {
            if (!$period->isHourly()) {
                throw new SpaceRuleException('The conference room is booked by the hour — pick a time slot.');
            }
            $open = (int) $this->settings->get('conference_open_hour', 8);
            $close = (int) $this->settings->get('conference_close_hour', 20);
            $start = (int) substr((string) $period->startTime, 0, 2);
            $end = (int) substr((string) $period->endTime, 0, 2);
            if (substr((string) $period->startTime, 3, 2) !== '00' || substr((string) $period->endTime, 3, 2) !== '00') {
                throw new SpaceRuleException('Conference slots start and end on the hour.');
            }
            if ($start < $open || $end > $close) {
                throw new SpaceRuleException(sprintf('The conference room is bookable between %d:00 and %d:00.', $open, $close));
            }
            if ($period->from === $today && $start <= (int) $this->clock->now()->format('G')) {
                throw new SpaceRuleException('That time slot has already started — pick a later one.');
            }
            return;
        }
        if ($period->isHourly()) {
            throw new SpaceRuleException(sprintf('%s is booked by the day or month, not by the hour.', $category->label()));
        }
        $maxMonths = (int) $this->settings->get('max_booking_months', 36);
        $limit = (new \DateTimeImmutable($period->from))->modify("+{$maxMonths} months")->format('Y-m-d');
        if ($period->to >= $limit && !($holder?->isStaff() ?? false)) {
            throw new SpaceRuleException(sprintf('Online requests can cover up to %d months. Please contact the front desk for longer tenures.', $maxMonths));
        }
    }
}
