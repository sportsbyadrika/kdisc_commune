<?php

declare(strict_types=1);

namespace App\Services\Layout;

use App\Core\Database;
use App\Enums\PlacementScope;
use App\Enums\SeatKind;
use App\Enums\SeatStatus;
use App\Services\AuditLog;
use App\Support\Clock;

/**
 * Draft layout versions for the Layout & Pricing Designer (spec 5.4).
 *
 * Versioning model — "stable keys, immutable versions":
 *   - Editing ALWAYS happens on a draft `layout_versions` row. createDraft() clones the published version's
 *     zones, seats (cabin/room parents + chairs) and facility placements into new rows of the draft.
 *   - seats.seat_key / zones.zone_key are copied by the clone: they are the stable identity of a seat / zone
 *     across versions (key = id of the row that first introduced it). New seats get key = their own id.
 *   - Published and archived rows are never modified, so booking_seats.seat_id (the exact row booked) keeps
 *     describing what was booked, while availability matches bookings by seat_key (AvailabilityService).
 *   - LayoutPublisher flips draft -> published and published -> archived in one transaction.
 *
 * The designer edits a JSON document (document()) and autosaves the WHOLE document (save()), which is
 * diffed against the draft rows: changed rows are updated, new rows (temporary ids "t123") inserted and
 * missing rows deleted. `revision` is an optimistic lock — a stale revision is a 409 conflict.
 */
final class LayoutDraftService
{
    /** Hidden zone that holds seats placed outside every zone (publish validation rejects them). */
    public const UNZONED = '~UNZONED';
    public const MAX_SEATS = 2000;
    public const MAX_ZONES = 200;
    public const MAX_PLACEMENTS = 600;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    // ------------------------------------------------------------------ read

    /** @return array<string, mixed>|null */
    public function version(int $id): ?array
    {
        return $this->db->first('SELECT * FROM layout_versions WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function published(int $floorId): ?array
    {
        return $this->db->first("SELECT * FROM layout_versions WHERE floor_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1", [$floorId]);
    }

    /** @return array<string, mixed>|null */
    public function draft(int $floorId): ?array
    {
        return $this->db->first("SELECT * FROM layout_versions WHERE floor_id = ? AND status = 'draft' ORDER BY version_no DESC LIMIT 1", [$floorId]);
    }

    /**
     * Versions of a floor, newest first, with who/when and seat counts.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $floorId): array
    {
        return $this->db->select(
            "SELECT lv.*, cu.name AS created_by_name, pu.name AS published_by_name, uu.name AS updated_by_name,
                    (SELECT COUNT(*) FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = lv.id AND s.parent_id IS NULL) AS units,
                    (SELECT COUNT(*) FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = lv.id AND s.kind = 'seat') AS chairs,
                    (SELECT COUNT(*) FROM zones z WHERE z.layout_version_id = lv.id AND z.code NOT LIKE '~%') AS zones
             FROM layout_versions lv
             LEFT JOIN staff_users cu ON cu.id = lv.created_by
             LEFT JOIN staff_users pu ON pu.id = lv.published_by
             LEFT JOIN staff_users uu ON uu.id = lv.updated_by
             WHERE lv.floor_id = ? ORDER BY lv.version_no DESC",
            [$floorId],
        );
    }

    /**
     * The editable document of a version: zones, seats and placements with percentage geometry.
     *
     * @return array{zones: list<array<string, mixed>>, seats: list<array<string, mixed>>, placements: list<array<string, mixed>>}
     */
    public function document(int $versionId): array
    {
        $zones = [];
        $unzoned = null;
        foreach ($this->db->select('SELECT * FROM zones WHERE layout_version_id = ? ORDER BY sort_order, id', [$versionId]) as $z) {
            if ($z['code'] === self::UNZONED) {
                $unzoned = (int) $z['id'];
                continue;
            }
            $zones[] = [
                'id' => (int) $z['id'],
                'key' => (int) $z['zone_key'],
                'code' => (string) $z['code'],
                'name' => (string) $z['name'],
                'category_id' => $z['seat_category_id'] !== null ? (int) $z['seat_category_id'] : null,
                'colour' => $z['colour'],
                'polygon' => $z['polygon'] !== null ? json_decode((string) $z['polygon'], true) : null,
                'x' => (float) $z['x_pct'], 'y' => (float) $z['y_pct'], 'w' => (float) $z['w_pct'], 'h' => (float) $z['h_pct'],
                'sort' => (int) $z['sort_order'],
            ];
        }
        $seats = [];
        foreach ($this->db->select(
            'SELECT s.* FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = ? ORDER BY s.parent_id IS NOT NULL, s.id',
            [$versionId],
        ) as $s) {
            $seats[] = [
                'id' => (int) $s['id'],
                'key' => (int) $s['seat_key'],
                'zone_id' => (int) $s['zone_id'] === $unzoned ? null : (int) $s['zone_id'],
                'parent_id' => $s['parent_id'] !== null ? (int) $s['parent_id'] : null,
                'code' => (string) $s['code'],
                'label' => (string) ($s['label'] ?? ''),
                'kind' => (string) $s['kind'],
                'capacity' => (int) $s['capacity'],
                'x' => (float) $s['x_pct'], 'y' => (float) $s['y_pct'], 'w' => (float) $s['w_pct'], 'h' => (float) $s['h_pct'],
                'rotation' => (int) $s['rotation'],
                'status' => (string) $s['status'],
                'status_from' => $s['status_from'],
                'status_to' => $s['status_to'],
                'notes' => (string) ($s['notes'] ?? ''),
                'tags' => (string) ($s['tags'] ?? ''),
            ];
        }
        $placements = [];
        foreach ($this->db->select('SELECT * FROM facility_placements WHERE layout_version_id = ? ORDER BY id', [$versionId]) as $p) {
            $placements[] = [
                'id' => (int) $p['id'],
                'facility_id' => (int) $p['facility_id'],
                'scope' => (string) $p['scope'],
                'scope_id' => (int) $p['scope_id'],
                'x' => $p['x_pct'] !== null ? (float) $p['x_pct'] : null,
                'y' => $p['y_pct'] !== null ? (float) $p['y_pct'] : null,
                'note' => (string) ($p['note'] ?? ''),
            ];
        }
        return ['zones' => $zones, 'seats' => $seats, 'placements' => $placements];
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * Start editing: clone the published version (or $fromVersionId, e.g. to restore an archived one) into a
     * new draft. Returns the existing draft when there already is one (and no source was requested).
     *
     * @return array<string, mixed> the draft layout_versions row
     */
    public function createDraft(int $floorId, int $staffId, ?int $fromVersionId = null): array
    {
        return $this->db->transaction(function (Database $db) use ($floorId, $staffId, $fromVersionId): array {
            $db->select('SELECT id FROM floors WHERE id = ? FOR UPDATE', [$floorId]);
            $existing = $this->draft($floorId);
            if ($existing !== null) {
                if ($fromVersionId !== null) {
                    throw new LayoutException('Discard the current draft before restoring an older version.', 409);
                }
                return $existing;
            }
            $published = $this->published($floorId);
            $source = $fromVersionId ?? ($published !== null ? (int) $published['id'] : null);
            if ($fromVersionId !== null) {
                $src = $this->version($fromVersionId);
                if ($src === null || (int) $src['floor_id'] !== $floorId) {
                    throw new LayoutException('That version does not belong to this floor.', 404);
                }
            }
            $no = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM layout_versions WHERE floor_id = ?', [$floorId]);
            $id = $db->insert('layout_versions', [
                'floor_id' => $floorId,
                'version_no' => $no,
                'status' => 'draft',
                'base_version_id' => $published['id'] ?? null,
                'revision' => 1,
                'notes' => $fromVersionId !== null ? sprintf('Restored from version %d', (int) ($this->version($fromVersionId)['version_no'] ?? 0)) : null,
                'created_by' => $staffId,
                'updated_by' => $staffId,
            ]);
            if ($source !== null) {
                $this->cloneInto($source, $id);
            }
            $this->audit->record('layout.draft.create', 'layout_version', $id, null, ['floor_id' => $floorId, 'version_no' => $no, 'from_version_id' => $source]);
            return (array) $this->version($id);
        });
    }

    /** Copy zones, seats (parents before chairs) and placements of one version into another, keeping keys. */
    private function cloneInto(int $sourceId, int $targetId): void
    {
        $zoneMap = [];
        foreach ($this->db->select('SELECT * FROM zones WHERE layout_version_id = ? ORDER BY id', [$sourceId]) as $z) {
            $old = (int) $z['id'];
            unset($z['id'], $z['created_at'], $z['updated_at']);
            $z['layout_version_id'] = $targetId;
            $zoneMap[$old] = $this->db->insert('zones', $z);
        }
        $seatMap = [];
        foreach ($this->db->select(
            'SELECT s.* FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = ? ORDER BY s.parent_id IS NOT NULL, s.id',
            [$sourceId],
        ) as $s) {
            $old = (int) $s['id'];
            unset($s['id'], $s['created_at'], $s['updated_at']);
            $s['zone_id'] = $zoneMap[(int) $s['zone_id']];
            $s['parent_id'] = $s['parent_id'] !== null ? ($seatMap[(int) $s['parent_id']] ?? null) : null;
            $seatMap[$old] = $this->db->insert('seats', $s);
        }
        foreach ($this->db->select('SELECT * FROM facility_placements WHERE layout_version_id = ?', [$sourceId]) as $p) {
            $scopeId = match ((string) $p['scope']) {
                'zone' => $zoneMap[(int) $p['scope_id']] ?? null,
                'seat' => $seatMap[(int) $p['scope_id']] ?? null,
                default => (int) $p['scope_id'],
            };
            if ($scopeId === null) {
                continue;
            }
            unset($p['id'], $p['created_at'], $p['updated_at']);
            $p['layout_version_id'] = $targetId;
            $p['scope_id'] = $scopeId;
            $this->db->insert('facility_placements', $p);
        }
    }

    /** Throw the draft away (zones/seats/placements cascade). Overrides priced only on draft-only seats go too. */
    public function discard(int $versionId): void
    {
        $this->db->transaction(function (Database $db) use ($versionId): void {
            $v = $db->first('SELECT * FROM layout_versions WHERE id = ? FOR UPDATE', [$versionId]);
            if ($v === null || $v['status'] !== 'draft') {
                throw new LayoutException('Only a draft can be discarded.', 409);
            }
            // seat/zone keys that exist only in this draft: their (never used) rates are removed with it
            $seatKeys = $db->column(
                'SELECT s.seat_key FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = ?
                   AND NOT EXISTS (SELECT 1 FROM seats o JOIN zones oz ON oz.id = o.zone_id WHERE o.seat_key = s.seat_key AND oz.layout_version_id <> ?)',
                [$versionId, $versionId],
            );
            $zoneKeys = $db->column(
                'SELECT z.zone_key FROM zones z WHERE z.layout_version_id = ?
                   AND NOT EXISTS (SELECT 1 FROM zones o WHERE o.zone_key = z.zone_key AND o.layout_version_id <> ?)',
                [$versionId, $versionId],
            );
            foreach (['seat' => $seatKeys, 'zone' => $zoneKeys] as $scope => $keys) {
                if ($keys !== []) {
                    $in = implode(',', array_fill(0, count($keys), '?'));
                    $db->execute("DELETE FROM rates WHERE scope = ? AND scope_id IN ({$in})", [$scope, ...$keys]);
                }
            }
            $db->execute('DELETE FROM layout_versions WHERE id = ?', [$versionId]);
            $this->audit->record('layout.draft.discard', 'layout_version', $versionId, ['floor_id' => (int) $v['floor_id'], 'version_no' => (int) $v['version_no']]);
        });
    }

    // ------------------------------------------------------------------ autosave

    /**
     * Persist the designer document into the draft rows.
     *
     * @param array<string, mixed> $doc {zones: [...], seats: [...], placements: [...]}
     * @return array{revision: int, ids: array<string, int>, updated_at: string}
     */
    public function save(int $versionId, int $revision, array $doc, int $staffId): array
    {
        $data = $this->normalize($doc);
        return $this->db->transaction(function (Database $db) use ($versionId, $revision, $data, $staffId): array {
            $v = $db->first('SELECT lv.*, u.name AS updated_by_name FROM layout_versions lv LEFT JOIN staff_users u ON u.id = lv.updated_by WHERE lv.id = ? FOR UPDATE', [$versionId]);
            if ($v === null || $v['status'] !== 'draft') {
                throw new LayoutException('This draft was published or discarded in another window. Reload to continue.', 409, ['reason' => 'not_draft']);
            }
            if ((int) $v['revision'] !== $revision) {
                throw new LayoutException(
                    sprintf('This draft was changed by %s at %s. Reload to get the latest version — your unsaved edits will be lost.', $v['updated_by_name'] ?? 'someone', substr((string) $v['updated_at'], 11, 5)),
                    409,
                    ['reason' => 'revision', 'revision' => (int) $v['revision']],
                );
            }
            $floorId = (int) $v['floor_id'];
            $ids = [];

            // ---- zones (insert/update; deletions after the seats moved out)
            $existingZones = [];
            $unzonedId = null;
            foreach ($db->select('SELECT * FROM zones WHERE layout_version_id = ?', [$versionId]) as $z) {
                if ($z['code'] === self::UNZONED) {
                    $unzonedId = (int) $z['id'];
                    continue;
                }
                $existingZones[(int) $z['id']] = $z;
            }
            $keepZones = [];
            foreach ($data['zones'] as $z) {
                $row = [
                    'seat_category_id' => $z['category_id'], 'code' => $z['code'], 'name' => $z['name'],
                    'polygon' => $z['polygon'] !== null ? json_encode($z['polygon']) : null,
                    'x_pct' => $z['x'], 'y_pct' => $z['y'], 'w_pct' => $z['w'], 'h_pct' => $z['h'],
                    'colour' => $z['colour'], 'sort_order' => $z['sort'],
                ];
                if (is_int($z['id'])) {
                    $old = $existingZones[$z['id']] ?? throw new LayoutException('A zone in your editor no longer exists in this draft. Reload the designer.', 409, ['reason' => 'stale']);
                    if ($this->changed($old, $row)) {
                        $db->update('zones', $row, ['id' => $z['id']]);
                    }
                    $keepZones[$z['id']] = true;
                } else {
                    $newId = $db->insert('zones', $row + ['layout_version_id' => $versionId]);
                    $db->execute('UPDATE zones SET zone_key = id WHERE id = ?', [$newId]);
                    $ids[$z['id']] = $newId;
                    $keepZones[$newId] = true;
                }
            }
            $zoneId = static fn (int|string|null $ref): ?int => $ref === null ? null : (is_int($ref) ? $ref : ($ids[$ref] ?? null));

            // ---- seats (parents first so chairs can reference them)
            $existingSeats = [];
            foreach ($db->select('SELECT s.* FROM seats s JOIN zones z ON z.id = s.zone_id WHERE z.layout_version_id = ?', [$versionId]) as $s) {
                $existingSeats[(int) $s['id']] = $s;
            }
            $seats = $data['seats'];
            usort($seats, static fn (array $a, array $b) => ($a['parent_id'] !== null) <=> ($b['parent_id'] !== null));
            $keepSeats = [];
            $statusChanges = [];
            foreach ($seats as $s) {
                $zid = $zoneId($s['zone_id']);
                if ($s['zone_id'] !== null && ($zid === null || !isset($keepZones[$zid]))) {
                    throw new LayoutException(sprintf('Seat %s refers to a zone that is not in this draft.', $s['code']));
                }
                if ($zid === null) {
                    $unzonedId ??= $this->unzonedZone($versionId);
                    $zid = $unzonedId;
                }
                $parent = $s['parent_id'] === null ? null : (is_int($s['parent_id']) ? $s['parent_id'] : ($ids[$s['parent_id']] ?? null));
                if ($s['parent_id'] !== null && ($parent === null || !isset($keepSeats[$parent]))) {
                    throw new LayoutException(sprintf('Chair %s belongs to a cabin/room that is not in this draft.', $s['code']));
                }
                $row = [
                    'zone_id' => $zid, 'parent_id' => $parent, 'code' => $s['code'], 'label' => $s['label'], 'kind' => $s['kind'],
                    'capacity' => $s['capacity'], 'x_pct' => $s['x'], 'y_pct' => $s['y'], 'w_pct' => $s['w'], 'h_pct' => $s['h'],
                    'rotation' => $s['rotation'], 'status' => $s['status'], 'status_from' => $s['status_from'], 'status_to' => $s['status_to'],
                    'notes' => $s['notes'], 'tags' => $s['tags'],
                ];
                if (is_int($s['id'])) {
                    $old = $existingSeats[$s['id']] ?? throw new LayoutException('A seat in your editor no longer exists in this draft. Reload the designer.', 409, ['reason' => 'stale']);
                    if ($this->changed($old, $row)) {
                        $db->update('seats', $row, ['id' => $s['id']]);
                        if ($old['status'] !== $row['status'] || (string) $old['status_from'] !== (string) $row['status_from'] || (string) $old['status_to'] !== (string) $row['status_to'] || (string) $old['notes'] !== (string) $row['notes']) {
                            $statusChanges[] = [$s['id'], ['status' => $old['status'], 'from' => $old['status_from'], 'to' => $old['status_to'], 'note' => $old['notes']], ['code' => $row['code'], 'status' => $row['status'], 'from' => $row['status_from'], 'to' => $row['status_to'], 'note' => $row['notes']]];
                        }
                    }
                    $keepSeats[$s['id']] = true;
                } else {
                    $newId = $db->insert('seats', $row);
                    $db->execute('UPDATE seats SET seat_key = id WHERE id = ?', [$newId]);
                    $ids[$s['id']] = $newId;
                    $keepSeats[$newId] = true;
                }
            }
            // removed seats: chairs first
            $gone = array_diff_key($existingSeats, $keepSeats);
            uasort($gone, static fn (array $a, array $b) => ($b['parent_id'] !== null) <=> ($a['parent_id'] !== null));
            foreach (array_keys($gone) as $id) {
                $db->execute('DELETE FROM seats WHERE id = ?', [$id]);
            }
            foreach (array_keys(array_diff_key($existingZones, $keepZones)) as $id) {
                $db->execute('DELETE FROM zones WHERE id = ?', [$id]);
            }
            if ($unzonedId !== null && (int) $db->scalar('SELECT COUNT(*) FROM seats WHERE zone_id = ?', [$unzonedId]) === 0) {
                $db->execute('DELETE FROM zones WHERE id = ?', [$unzonedId]);
            }

            // ---- facility placements
            $existingPl = [];
            foreach ($db->select('SELECT * FROM facility_placements WHERE layout_version_id = ?', [$versionId]) as $p) {
                $existingPl[(int) $p['id']] = $p;
            }
            $keepPl = [];
            foreach ($data['placements'] as $p) {
                $scopeId = match ($p['scope']) {
                    'floor' => $floorId,
                    'zone' => $zoneId($p['scope_id']),
                    default => is_int($p['scope_id']) ? $p['scope_id'] : ($ids[$p['scope_id']] ?? null),
                };
                if ($scopeId === null || ($p['scope'] === 'zone' && !isset($keepZones[$scopeId])) || ($p['scope'] === 'seat' && !isset($keepSeats[$scopeId]))) {
                    throw new LayoutException('A facility is attached to a zone or seat that is not in this draft.');
                }
                $row = ['facility_id' => $p['facility_id'], 'scope' => $p['scope'], 'scope_id' => $scopeId, 'x_pct' => $p['x'], 'y_pct' => $p['y'], 'note' => $p['note']];
                if (is_int($p['id'])) {
                    $old = $existingPl[$p['id']] ?? throw new LayoutException('A facility in your editor no longer exists in this draft. Reload the designer.', 409, ['reason' => 'stale']);
                    if ($this->changed($old, $row)) {
                        $db->update('facility_placements', $row, ['id' => $p['id']]);
                    }
                    $keepPl[$p['id']] = true;
                } else {
                    $newId = $db->insert('facility_placements', $row + ['layout_version_id' => $versionId]);
                    $ids[$p['id']] = $newId;
                    $keepPl[$newId] = true;
                }
            }
            foreach (array_keys(array_diff_key($existingPl, $keepPl)) as $id) {
                $db->execute('DELETE FROM facility_placements WHERE id = ?', [$id]);
            }

            foreach ($statusChanges as [$seatId, $old, $new]) {
                $this->audit->record('seat.status.draft', 'seat', $seatId, $old, $new + ['layout_version_id' => $versionId]);
            }
            $now = $this->clock->sql();
            $db->execute('UPDATE layout_versions SET revision = revision + 1, updated_by = ?, updated_at = ? WHERE id = ?', [$staffId, $now, $versionId]);
            return ['revision' => $revision + 1, 'ids' => $ids, 'updated_at' => $now];
        });
    }

    private function unzonedZone(int $versionId): int
    {
        $id = $this->db->insert('zones', [
            'layout_version_id' => $versionId, 'seat_category_id' => null, 'code' => self::UNZONED, 'name' => 'Outside any zone',
            'x_pct' => 0, 'y_pct' => 0, 'w_pct' => 0, 'h_pct' => 0, 'sort_order' => 9999,
        ]);
        $this->db->execute('UPDATE zones SET zone_key = id WHERE id = ?', [$id]);
        return $id;
    }

    /**
     * @param array<string, mixed> $old DB row
     * @param array<string, mixed> $new normalized values
     */
    private function changed(array $old, array $new): bool
    {
        foreach ($new as $k => $v) {
            $o = $old[$k] ?? null;
            if (($o === null) !== ($v === null)) {
                return true;
            }
            if ($v === null) {
                continue;
            }
            if (is_float($v) || is_int($v)) {
                if (abs((float) $o - (float) $v) > 0.0005) {
                    return true;
                }
            } elseif ($k === 'polygon') {
                if (json_encode(json_decode((string) $o, true)) !== json_encode(json_decode((string) $v, true))) {
                    return true;
                }
            } elseif ((string) $o !== (string) $v) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ validation of the posted document

    /**
     * Server-side validation of the whole document: types, enums, lengths and geometry (0-100 %).
     *
     * @param array<string, mixed> $doc
     * @return array{zones: list<array<string, mixed>>, seats: list<array<string, mixed>>, placements: list<array<string, mixed>>}
     */
    public function normalize(array $doc): array
    {
        $zones = is_array($doc['zones'] ?? null) ? array_values($doc['zones']) : [];
        $seats = is_array($doc['seats'] ?? null) ? array_values($doc['seats']) : [];
        $placements = is_array($doc['placements'] ?? null) ? array_values($doc['placements']) : [];
        if (count($zones) > self::MAX_ZONES || count($seats) > self::MAX_SEATS || count($placements) > self::MAX_PLACEMENTS) {
            throw new LayoutException('This layout is too large to save.');
        }
        $categories = array_map('intval', $this->db->column('SELECT id FROM seat_categories'));
        $facilities = array_map('intval', $this->db->column('SELECT id FROM facilities'));
        $seen = [];

        $outZones = [];
        foreach ($zones as $i => $z) {
            if (!is_array($z)) {
                throw new LayoutException('Invalid zone data.');
            }
            $label = sprintf('Zone %s', is_string($z['name'] ?? null) && $z['name'] !== '' ? $z['name'] : '#' . ($i + 1));
            $id = $this->ref($z['id'] ?? null, $label, $seen);
            $name = $this->text($z['name'] ?? '', 100, $label . ' name', true);
            $polygon = null;
            if (isset($z['polygon']) && is_array($z['polygon']) && $z['polygon'] !== []) {
                if (count($z['polygon']) < 3 || count($z['polygon']) > 64) {
                    throw new LayoutException($label . ': a polygon needs 3 to 64 points.');
                }
                foreach ($z['polygon'] as $pt) {
                    if (!is_array($pt) || count($pt) !== 2) {
                        throw new LayoutException($label . ': invalid polygon point.');
                    }
                    $polygon[] = [$this->pct($pt[0], $label), $this->pct($pt[1], $label)];
                }
                $xs = array_column($polygon, 0);
                $ys = array_column($polygon, 1);
                [$x, $y, $w, $h] = [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
            } else {
                [$x, $y, $w, $h] = $this->rect($z, $label);
            }
            $cat = $z['category_id'] ?? null;
            if ($cat !== null && $cat !== '' && !in_array((int) $cat, $categories, true)) {
                throw new LayoutException($label . ': unknown space type.');
            }
            $colour = $z['colour'] ?? null;
            if ($colour !== null && $colour !== '' && (!is_string($colour) || preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1)) {
                throw new LayoutException($label . ': colour must look like #22c55e.');
            }
            $outZones[] = [
                'id' => $id,
                'code' => $this->code($z['code'] ?? '', $label, 'Z' . ($i + 1)),
                'name' => $name,
                'category_id' => $cat !== null && $cat !== '' ? (int) $cat : null,
                'colour' => $colour !== null && $colour !== '' ? strtolower($colour) : null,
                'polygon' => $polygon,
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'sort' => max(0, min(9000, (int) ($z['sort'] ?? $i))),
            ];
        }

        $outSeats = [];
        foreach ($seats as $s) {
            if (!is_array($s)) {
                throw new LayoutException('Invalid seat data.');
            }
            $label = sprintf('Seat %s', is_string($s['code'] ?? null) && $s['code'] !== '' ? $s['code'] : '(new)');
            $kind = SeatKind::tryFrom((string) ($s['kind'] ?? 'seat')) ?? throw new LayoutException($label . ': unknown kind.');
            $status = SeatStatus::tryFrom((string) ($s['status'] ?? 'available')) ?? throw new LayoutException($label . ': unknown status.');
            [$x, $y, $w, $h] = $this->rect($s, $label);
            $from = $this->date($s['status_from'] ?? null, $label);
            $to = $this->date($s['status_to'] ?? null, $label);
            if ($from !== null && $to !== null && $to < $from) {
                throw new LayoutException($label . ': the status end date is before its start date.');
            }
            $capacity = filter_var($s['capacity'] ?? 1, FILTER_VALIDATE_INT);
            if ($capacity === false || $capacity < 1 || $capacity > 99) {
                throw new LayoutException($label . ': capacity must be 1 to 99.');
            }
            $rotation = filter_var($s['rotation'] ?? 0, FILTER_VALIDATE_INT);
            if ($rotation === false || abs($rotation) > 720) {
                throw new LayoutException($label . ': invalid rotation.');
            }
            $rotation = (($rotation % 360) + 360) % 360;
            $outSeats[] = [
                'id' => $this->ref($s['id'] ?? null, $label, $seen),
                'zone_id' => $this->optionalRef($s['zone_id'] ?? null, $label),
                'parent_id' => $this->optionalRef($s['parent_id'] ?? null, $label),
                'code' => $this->code($s['code'] ?? '', $label),
                'label' => $this->text($s['label'] ?? '', 50, $label . ' label') ?: null,
                'kind' => $kind->value,
                'capacity' => $kind === SeatKind::Seat ? 1 : $capacity,
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'rotation' => $rotation,
                'status' => $status->value,
                'status_from' => $status === SeatStatus::Available ? null : $from,
                'status_to' => $status === SeatStatus::Available ? null : $to,
                'notes' => $this->text($s['notes'] ?? '', 500, $label . ' note') ?: null,
                'tags' => $this->text($s['tags'] ?? '', 255, $label . ' tags') ?: null,
            ];
        }

        $outPl = [];
        foreach ($placements as $p) {
            if (!is_array($p)) {
                throw new LayoutException('Invalid facility placement.');
            }
            $fid = (int) ($p['facility_id'] ?? 0);
            if (!in_array($fid, $facilities, true)) {
                throw new LayoutException('Unknown facility on the plan.');
            }
            $scope = PlacementScope::tryFrom((string) ($p['scope'] ?? 'floor')) ?? throw new LayoutException('Invalid facility scope.');
            $outPl[] = [
                'id' => $this->ref($p['id'] ?? null, 'Facility', $seen),
                'facility_id' => $fid,
                'scope' => $scope->value,
                'scope_id' => $scope === PlacementScope::Floor ? null : ($this->optionalRef($p['scope_id'] ?? null, 'Facility') ?? throw new LayoutException('A facility placement is missing its zone/seat.')),
                'x' => ($p['x'] ?? null) === null ? null : $this->pct($p['x'], 'Facility'),
                'y' => ($p['y'] ?? null) === null ? null : $this->pct($p['y'], 'Facility'),
                'note' => $this->text($p['note'] ?? '', 150, 'Facility note') ?: null,
            ];
        }
        return ['zones' => $outZones, 'seats' => $outSeats, 'placements' => $outPl];
    }

    /** @param array<string, true> $seen */
    private function ref(mixed $v, string $label, array &$seen): int|string
    {
        $ref = $this->optionalRef($v, $label) ?? throw new LayoutException($label . ': missing id.');
        $k = (is_int($ref) ? 'i' : 's') . $ref;
        if (isset($seen[$k])) {
            throw new LayoutException($label . ': duplicate id.');
        }
        $seen[$k] = true;
        return $ref;
    }

    /** Existing row id (int) or a temporary client id ("t12"). */
    private function optionalRef(mixed $v, string $label): int|string|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^t\d{1,12}$/', $v) === 1) {
            return $v;
        }
        throw new LayoutException($label . ': invalid reference.');
    }

    private function pct(mixed $v, string $label): float
    {
        if (!is_numeric($v) || !is_finite((float) $v) || (float) $v < -0.001 || (float) $v > 100.001) {
            throw new LayoutException($label . ': coordinates must be between 0 and 100 % of the image.');
        }
        return round(max(0.0, min(100.0, (float) $v)), 3);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rect(array $r, string $label): array
    {
        $x = $this->pct($r['x'] ?? null, $label);
        $y = $this->pct($r['y'] ?? null, $label);
        $w = $this->pct($r['w'] ?? null, $label);
        $h = $this->pct($r['h'] ?? null, $label);
        if ($w <= 0 || $h <= 0) {
            throw new LayoutException($label . ': width and height must be greater than 0.');
        }
        if ($x + $w > 100.01 || $y + $h > 100.01) {
            throw new LayoutException($label . ' extends beyond the edge of the plan.');
        }
        return [$x, $y, $w, $h];
    }

    private function text(mixed $v, int $max, string $label, bool $required = false): string
    {
        if ($v !== null && !is_scalar($v)) {
            throw new LayoutException($label . ' is invalid.');
        }
        $t = trim((string) $v);
        if ($required && $t === '') {
            throw new LayoutException($label . ' is required.');
        }
        if (mb_strlen($t) > $max) {
            throw new LayoutException(sprintf('%s may not be longer than %d characters.', $label, $max));
        }
        return $t;
    }

    private function code(mixed $v, string $label, string $default = ''): string
    {
        $c = strtoupper($this->text($v, 20, $label . ' code'));
        $c = $c !== '' ? $c : $default;
        if ($c === '' || preg_match('/^[A-Z0-9][A-Z0-9-]*$/', $c) !== 1) {
            throw new LayoutException($label . ': codes use letters, digits and dashes (e.g. G-FX-01).');
        }
        return $c;
    }

    private function date(mixed $v, string $label): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || strtotime($v) === false) {
            throw new LayoutException($label . ': invalid date.');
        }
        return $v;
    }
}
