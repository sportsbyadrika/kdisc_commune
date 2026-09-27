<?php

declare(strict_types=1);

namespace App\Services\Layout;

use App\Core\Database;
use App\Enums\BillingUnit;
use App\Enums\SeatCategory;
use App\Services\AuditLog;
use App\Services\Pricing\PriceResolver;
use App\Services\Space\AvailabilityService;
use App\Support\Clock;

/**
 * Publish validation + publishing of a draft layout version (spec 5.4 "Preview and publish").
 *
 * check() returns blocking ERRORS (duplicate seat codes on the floor, seats outside a zone with a space type,
 * loose seats in cabin/conference zones, cabins/rooms without chairs or capacity, space types without a base
 * rate) and WARNINGS that need an explicit confirmation (seats with active or future bookings that were removed,
 * moved to another space type or blocked; capacity not matching the chairs).
 *
 * publish() — one transaction: locks the draft, the floor and the published seat rows (the same row locks
 * SeatHoldService / BookingService take, so no hold or booking can interleave), re-validates, archives the
 * published version, publishes the draft, re-points live seat holds to the new rows by seat_key and audits the
 * change. Bookings are NOT touched: booking_seats keep their seat row + seat_key and AvailabilityService matches
 * them by seat_key, so availability and booking history are unaffected.
 */
final class LayoutPublisher
{
    public function __construct(
        private readonly Database $db,
        private readonly LayoutDraftService $drafts,
        private readonly PriceResolver $prices,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{errors: list<array{message: string, items: list<string>}>, warnings: list<array{message: string, items: list<string>}>, summary: array<string, int>}
     */
    public function check(int $versionId): array
    {
        $v = $this->drafts->version($versionId) ?? throw new LayoutException('Layout version not found.', 404);
        $floorId = (int) $v['floor_id'];
        $errors = [];
        $warnings = [];
        $err = static function (string $message, array $items = []) use (&$errors): void {
            $errors[] = ['message' => $message, 'items' => array_values(array_map('strval', $items))];
        };
        $warn = static function (string $message, array $items = []) use (&$warnings): void {
            $warnings[] = ['message' => $message, 'items' => array_values(array_map('strval', $items))];
        };

        $seats = $this->seatsOf($versionId);
        $units = array_filter($seats, static fn (array $s) => $s['parent_id'] === null);
        if ($units === []) {
            $warn('This floor has no bookable seats — visitors will see an empty plan.');
        }

        // duplicate codes on the floor
        $codes = array_count_values(array_map(static fn (array $s) => (string) $s['code'], $seats));
        $dupes = array_keys(array_filter($codes, static fn (int $n) => $n > 1));
        if ($dupes !== []) {
            sort($dupes);
            $err('Seat codes must be unique on the floor. Renumber these seats:', $dupes);
        }

        // every seat in a zone with a space type
        $unzoned = array_filter($seats, static fn (array $s) => $s['category'] === null);
        if ($unzoned !== []) {
            $err('Every seat must sit inside a zone with a space type. Move these seats into a zone (or give their zone a space type):', array_column($unzoned, 'code'));
        }

        $chairs = [];
        foreach ($seats as $s) {
            if ($s['parent_id'] !== null) {
                $chairs[(int) $s['parent_id']][] = $s;
            }
        }
        $loose = [];
        $wrongKind = [];
        foreach ($units as $u) {
            $cat = SeatCategory::tryFrom((string) $u['category']);
            if ($cat === null) {
                continue;
            }
            if ($cat->wholeUnitOnly() && $u['kind'] === 'seat') {
                $loose[] = $u['code'] . ' (' . $u['zone_name'] . ')';
            }
            if (!$cat->wholeUnitOnly() && $u['kind'] !== 'seat') {
                $wrongKind[] = $u['code'] . ' in ' . $u['zone_name'];
            }
            if ($u['kind'] !== 'seat') {
                $n = count($chairs[(int) $u['id']] ?? []);
                $what = $u['kind'] === 'room' ? 'Conference room' : 'Cabin';
                if ($n === 0) {
                    $err(sprintf('%s %s has no chairs — add chairs or delete it.', $what, $u['code']));
                }
                if ((int) $u['capacity'] < 1) {
                    $err(sprintf('%s %s needs a capacity of at least 1.', $what, $u['code']));
                } elseif ($n > 0 && $n !== (int) $u['capacity']) {
                    $warn(sprintf('%s %s has capacity %d but %d chairs drawn.', $what, $u['code'], (int) $u['capacity'], $n));
                }
            }
        }
        if ($loose !== []) {
            $err('Cabins and the conference room are booked whole: these single seats sit in a cabin/conference zone. Use a cabin/room with chairs, or change the zone’s space type:', $loose);
        }
        if ($wrongKind !== []) {
            $err('Cabins/rooms can only sit in a Cabin or Conference zone:', $wrongKind);
        }

        // base rates for every space type used
        $today = $this->clock->today();
        $used = array_unique(array_filter(array_map(static fn (array $s) => $s['category'], $units)));
        foreach ($this->db->select('SELECT id, code, short_name FROM seat_categories ORDER BY sort_order') as $c) {
            $cat = SeatCategory::tryFrom((string) $c['code']);
            if ($cat === null || !in_array($cat->value, $used, true)) {
                continue;
            }
            $missing = array_filter($cat->billingUnits(), fn (BillingUnit $u) => $this->prices->forCategory((int) $c['id'], $u, $today) === null);
            if ($missing !== []) {
                $err(sprintf('%s has no base rate per %s — add one on the Rates tab.', $c['short_name'], implode(' / ', array_map(static fn (BillingUnit $u) => $u->value, $missing))));
            }
        }

        // impact on active / future bookings of this floor
        $draftByKey = [];
        foreach ($seats as $s) {
            $draftByKey[(int) $s['seat_key']] = $s;
        }
        $st = AvailabilityService::activeStatuses();
        $in = implode(',', array_fill(0, count($st), '?'));
        $removed = [];
        $moved = [];
        $blocked = [];
        foreach ($this->db->select(
            "SELECT bs.seat_key, bs.start_date, bs.end_date, b.booking_no, s.code, sc.code AS category
             FROM booking_seats bs
             JOIN bookings b ON b.id = bs.booking_id
             JOIN seats s ON s.id = bs.seat_id
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.floor_id = ?
             LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE b.status IN ({$in}) AND bs.released_at IS NULL AND bs.end_date >= ?
             ORDER BY s.code, bs.start_date",
            [$floorId, ...$st, $today],
        ) as $b) {
            $label = sprintf('%s — %s (%s → %s)', $b['code'], $b['booking_no'], $b['start_date'], $b['end_date']);
            $now = $draftByKey[(int) $b['seat_key']] ?? null;
            if ($now === null) {
                $removed[] = $label;
            } elseif ($now['category'] !== $b['category']) {
                $moved[] = sprintf('%s → now %s', $label, SeatCategory::tryFrom((string) $now['category'])?->shortLabel() ?? 'no space type');
            } elseif ($now['status'] !== 'available' && ($now['status_from'] === null || $now['status_from'] <= $b['end_date']) && ($now['status_to'] === null || $now['status_to'] >= $b['start_date'])) {
                $blocked[] = $label;
            }
        }
        if ($removed !== []) {
            $warn('These seats have active or upcoming bookings but were removed from the layout. The bookings stay valid; reception will need to re-allot them:', $removed);
        }
        if ($moved !== []) {
            $warn('These booked seats moved to another space type:', $moved);
        }
        if ($blocked !== []) {
            $warn('These booked seats are blocked / under maintenance during their booking:', $blocked);
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'summary' => $this->diff($floorId, $seats)];
    }

    /**
     * Publish the draft. With warnings and $confirmWarnings = false nothing changes and a 409 with the report
     * is thrown so the UI can ask for confirmation.
     *
     * @return array<string, mixed> the report + published version
     */
    public function publish(int $versionId, int $staffId, bool $confirmWarnings = false, ?string $notes = null): array
    {
        return $this->db->transaction(function (Database $db) use ($versionId, $staffId, $confirmWarnings, $notes): array {
            $v = $db->first('SELECT * FROM layout_versions WHERE id = ? FOR UPDATE', [$versionId]);
            if ($v === null || $v['status'] !== 'draft') {
                throw new LayoutException('Only a draft can be published — it may have been published already.', 409, ['reason' => 'not_draft']);
            }
            $floorId = (int) $v['floor_id'];
            $db->select('SELECT id FROM floors WHERE id = ? FOR UPDATE', [$floorId]);
            $old = $db->first("SELECT * FROM layout_versions WHERE floor_id = ? AND status = 'published' FOR UPDATE", [$floorId]);
            $oldId = $old !== null ? (int) $old['id'] : null;
            if ($oldId !== null) {
                // same row locks as holds/bookings take → serialises with them
                $db->select('SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = ? ORDER BY s.id FOR UPDATE', [$oldId]);
            }

            $report = $this->check($versionId);
            if ($report['errors'] !== []) {
                throw new LayoutException(sprintf('Fix %d problem%s before publishing.', count($report['errors']), count($report['errors']) === 1 ? '' : 's'), 422, ['validation' => $report]);
            }
            if ($report['warnings'] !== [] && !$confirmWarnings) {
                throw new LayoutException('Review the warnings, then confirm to publish.', 409, ['validation' => $report, 'needs_confirmation' => true]);
            }

            $now = $this->clock->sql();
            if ($oldId !== null) {
                $db->execute("UPDATE layout_versions SET status = 'archived', archived_at = ? WHERE id = ?", [$now, $oldId]);
            }
            $db->execute("DELETE FROM zones WHERE layout_version_id = ? AND code = ? AND NOT EXISTS (SELECT 1 FROM seats s WHERE s.zone_id = zones.id)", [$versionId, LayoutDraftService::UNZONED]);
            $db->update('layout_versions', [
                'status' => 'published', 'published_by' => $staffId, 'published_at' => $now, 'updated_by' => $staffId,
                'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : $v['notes'],
                'summary' => json_encode($report['summary'] + ['warnings' => count($report['warnings'])]),
            ], ['id' => $versionId]);

            $moved = 0;
            if ($oldId !== null) {
                // live holds follow their seat to the new row; holds on removed seats are dropped
                $moved = $db->execute(
                    'UPDATE seat_holds h
                     JOIN seats o ON o.id = h.seat_id JOIN zones oz ON oz.id = o.zone_id AND oz.layout_version_id = ?
                     JOIN seats n ON n.seat_key = o.seat_key JOIN zones nz ON nz.id = n.zone_id AND nz.layout_version_id = ?
                     SET h.seat_id = n.id',
                    [$oldId, $versionId],
                );
                $db->execute('DELETE h FROM seat_holds h JOIN seats o ON o.id = h.seat_id JOIN zones oz ON oz.id = o.zone_id AND oz.layout_version_id = ?', [$oldId]);
                $this->auditStatusChanges($oldId, $versionId);
            }
            $this->audit->record('layout.publish', 'layout_version', $versionId, $oldId !== null ? ['published_version_id' => $oldId, 'version_no' => (int) $old['version_no']] : null, [
                'floor_id' => $floorId, 'version_no' => (int) $v['version_no'], 'summary' => $report['summary'],
                'warnings' => array_map(static fn (array $w) => $w['message'] . ' ' . implode('; ', $w['items']), $report['warnings']),
                'holds_moved' => $moved,
            ]);
            return $report + ['version' => $db->first('SELECT * FROM layout_versions WHERE id = ?', [$versionId])];
        });
    }

    /** Audit blocks/maintenance/unblocks that go live with this publish. */
    private function auditStatusChanges(int $oldId, int $newId): void
    {
        $old = [];
        foreach ($this->seatsOf($oldId) as $s) {
            $old[(int) $s['seat_key']] = $s;
        }
        foreach ($this->seatsOf($newId) as $s) {
            $o = $old[(int) $s['seat_key']] ?? null;
            $was = $o !== null ? [$o['status'], $o['status_from'], $o['status_to'], $o['notes']] : ['available', null, null, null];
            $now = [$s['status'], $s['status_from'], $s['status_to'], $s['notes']];
            if (array_map('strval', $was) !== array_map('strval', $now)) {
                $this->audit->record(
                    $s['status'] === 'available' ? 'seat.unblock' : 'seat.block',
                    'seat',
                    (int) $s['id'],
                    ['status' => $was[0], 'from' => $was[1], 'to' => $was[2], 'note' => $was[3]],
                    ['code' => $s['code'], 'status' => $now[0], 'from' => $now[1], 'to' => $now[2], 'note' => $now[3], 'seat_key' => (int) $s['seat_key']],
                    $s['notes'] !== null ? (string) $s['notes'] : null,
                );
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function seatsOf(int $versionId): array
    {
        return $this->db->select(
            'SELECT s.*, z.name AS zone_name, z.code AS zone_code, sc.code AS category
             FROM seats s JOIN zones z ON z.id = s.zone_id LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id
             WHERE z.layout_version_id = ? ORDER BY s.code',
            [$versionId],
        );
    }

    /**
     * Change counts against the published version, by seat_key.
     *
     * @param list<array<string, mixed>> $draftSeats
     * @return array<string, int>
     */
    private function diff(int $floorId, array $draftSeats): array
    {
        $pub = $this->drafts->published($floorId);
        $old = [];
        if ($pub !== null) {
            foreach ($this->seatsOf((int) $pub['id']) as $s) {
                $old[(int) $s['seat_key']] = $s;
            }
        }
        $out = ['units' => 0, 'chairs' => 0, 'added' => 0, 'removed' => 0, 'moved' => 0, 'recoded' => 0, 'recategorised' => 0, 'status_changed' => 0];
        $seen = [];
        foreach ($draftSeats as $s) {
            $out['units'] += $s['parent_id'] === null ? 1 : 0;
            $out['chairs'] += $s['kind'] === 'seat' ? 1 : 0;
            $key = (int) $s['seat_key'];
            $seen[$key] = true;
            $o = $old[$key] ?? null;
            if ($o === null) {
                $out['added']++;
                continue;
            }
            foreach (['x_pct', 'y_pct', 'w_pct', 'h_pct', 'rotation'] as $k) {
                if (abs((float) $o[$k] - (float) $s[$k]) > 0.0005) {
                    $out['moved']++;
                    break;
                }
            }
            $out['recoded'] += $o['code'] !== $s['code'] ? 1 : 0;
            $out['recategorised'] += $o['category'] !== $s['category'] ? 1 : 0;
            $out['status_changed'] += $o['status'] !== $s['status'] ? 1 : 0;
        }
        $out['removed'] = count(array_diff_key($old, $seen));
        return $out;
    }
}
