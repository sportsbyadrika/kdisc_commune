<?php

declare(strict_types=1);

namespace App\Services\Layout;

use App\Core\Database;
use App\Services\AuditLog;

/**
 * Level-1 building page of the Designer: building / floor photos, floor hotspot polygons on the building
 * photo (percent coordinates), and adding / removing floors. Not versioned — changes are live immediately
 * and audit-logged (photos, hotspots and floors carry no booking state).
 */
final class BuildingService
{
    public function __construct(
        private readonly Database $db,
        private readonly PhotoStore $photos,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function building(): ?array
    {
        return $this->db->first('SELECT * FROM buildings ORDER BY id LIMIT 1');
    }

    /** @return list<array<string, mixed>> floors with decoded hotspots + seat counts of the published layout */
    public function floors(): array
    {
        $rows = $this->db->select(
            "SELECT f.*, (SELECT COUNT(*) FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
                          WHERE lv.floor_id = f.id AND s.kind = 'seat') AS chairs,
                    (SELECT lv.version_no FROM layout_versions lv WHERE lv.floor_id = f.id AND lv.status = 'published' LIMIT 1) AS published_no,
                    (SELECT lv.version_no FROM layout_versions lv WHERE lv.floor_id = f.id AND lv.status = 'draft' LIMIT 1) AS draft_no
             FROM floors f ORDER BY f.level DESC, f.sort_order",
        );
        foreach ($rows as &$r) {
            $r['hotspot'] = json_decode((string) ($r['hotspot_polygon'] ?? '[]'), true) ?: [];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{path: string, width: int, height: int, original: string}
     */
    public function uploadBuildingPhoto(?array $file): array
    {
        $b = $this->building() ?? throw new LayoutException('No building configured.', 404);
        $stored = $this->photos->store($file);
        $this->db->update('buildings', ['photo_path' => $stored['path'], 'photo_w' => $stored['width'], 'photo_h' => $stored['height'], 'photo_original' => $stored['original']], ['id' => (int) $b['id']]);
        $this->photos->deleteIfUploaded($b['photo_path']);
        $this->audit->record('building.photo', 'building', (int) $b['id'], ['photo_path' => $b['photo_path'], 'w' => $b['photo_w'], 'h' => $b['photo_h']], $stored);
        return $stored;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{path: string, width: int, height: int, original: string}
     */
    public function uploadFloorPhoto(int $floorId, ?array $file): array
    {
        $f = $this->db->first('SELECT * FROM floors WHERE id = ?', [$floorId]) ?? throw new LayoutException('Floor not found.', 404);
        $stored = $this->photos->store($file);
        $this->db->update('floors', ['photo_path' => $stored['path'], 'photo_w' => $stored['width'], 'photo_h' => $stored['height'], 'photo_original' => $stored['original']], ['id' => $floorId]);
        $this->photos->deleteIfUploaded($f['photo_path']);
        $this->audit->record('floor.photo', 'floor', $floorId, ['photo_path' => $f['photo_path'], 'w' => $f['photo_w'], 'h' => $f['photo_h']], $stored);
        return $stored;
    }

    /**
     * Save names, levels and hotspot polygons of all floors (one JSON payload from the hotspot editor).
     *
     * @param list<mixed> $floors [{id, name, level, hotspot: [[x,y],...]}]
     */
    public function saveHotspots(array $floors): void
    {
        $known = [];
        foreach ($this->db->select('SELECT * FROM floors') as $f) {
            $known[(int) $f['id']] = $f;
        }
        $clean = [];
        foreach ($floors as $f) {
            if (!is_array($f) || !isset($known[(int) ($f['id'] ?? 0)])) {
                throw new LayoutException('Unknown floor in the hotspot editor — reload the page.');
            }
            $name = trim((string) ($f['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) {
                throw new LayoutException('Every floor needs a name (up to 100 characters).');
            }
            $level = filter_var($f['level'] ?? null, FILTER_VALIDATE_INT);
            if ($level === false || $level < -5 || $level > 60) {
                throw new LayoutException(sprintf('%s: level must be a whole number between -5 and 60.', $name));
            }
            $points = [];
            foreach (is_array($f['hotspot'] ?? null) ? $f['hotspot'] : [] as $p) {
                if (!is_array($p) || count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])
                    || (float) $p[0] < 0 || (float) $p[0] > 100 || (float) $p[1] < 0 || (float) $p[1] > 100) {
                    throw new LayoutException(sprintf('%s: hotspot points must be inside the photo (0–100 %%).', $name));
                }
                $points[] = [round((float) $p[0], 2), round((float) $p[1], 2)];
            }
            if ($points !== [] && (count($points) < 3 || count($points) > 40)) {
                throw new LayoutException(sprintf('%s: a hotspot needs 3 to 40 points.', $name));
            }
            $clean[(int) $f['id']] = ['name' => $name, 'level' => $level, 'hotspot_polygon' => json_encode($points)];
        }
        $this->db->transaction(function () use ($clean, $known): void {
            foreach ($clean as $id => $row) {
                $old = $known[$id];
                if ($old['name'] === $row['name'] && (int) $old['level'] === $row['level'] && json_encode(json_decode((string) $old['hotspot_polygon'], true)) === $row['hotspot_polygon']) {
                    continue;
                }
                $this->db->update('floors', $row, ['id' => $id]);
                $this->audit->record('floor.update', 'floor', $id, ['name' => $old['name'], 'level' => (int) $old['level'], 'hotspot' => json_decode((string) $old['hotspot_polygon'], true)], ['name' => $row['name'], 'level' => $row['level'], 'hotspot' => json_decode($row['hotspot_polygon'], true)]);
            }
        });
    }

    /**
     * @param array{name: string, code: string, level: int|string} $data
     */
    public function addFloor(array $data): int
    {
        $b = $this->building() ?? throw new LayoutException('No building configured.', 404);
        $code = strtoupper(trim($data['code']));
        if ($this->db->scalar('SELECT id FROM floors WHERE building_id = ? AND code = ?', [(int) $b['id'], $code]) !== null) {
            throw new \App\Core\Exceptions\ValidationException(['code' => ['Another floor already uses this seat-code prefix.']]);
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($data['name'])), '-') ?: 'floor';
        $base = $slug;
        for ($i = 2; $this->db->scalar('SELECT id FROM floors WHERE building_id = ? AND slug = ?', [(int) $b['id'], $slug]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }
        $level = (int) $data['level'];
        // default hotspot: a band above the highest existing one
        $top = 100.0;
        foreach ($this->floors() as $f) {
            foreach ($f['hotspot'] as $p) {
                $top = min($top, (float) $p[1]);
            }
        }
        $y2 = max(8.0, $top);
        $y1 = max(2.0, $y2 - 20);
        $id = $this->db->insert('floors', [
            'building_id' => (int) $b['id'], 'name' => trim($data['name']), 'slug' => $slug, 'code' => $code, 'level' => $level,
            'photo_path' => 'media/placeholder.svg', 'photo_w' => 1600, 'photo_h' => 1000,
            'hotspot_polygon' => json_encode([[14, $y1], [86, $y1], [86, $y2], [14, $y2]]),
            'sort_order' => (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM floors'),
        ]);
        $this->audit->record('floor.create', 'floor', $id, null, ['name' => $data['name'], 'code' => $code, 'level' => $level]);
        return $id;
    }

    /** Remove a floor that never had a booking (its layout versions, zones and seats cascade). */
    public function removeFloor(int $floorId): void
    {
        $f = $this->db->first('SELECT * FROM floors WHERE id = ?', [$floorId]) ?? throw new LayoutException('Floor not found.', 404);
        $booked = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id WHERE lv.floor_id = ?',
            [$floorId],
        );
        if ($booked > 0) {
            throw new LayoutException(sprintf('%s has booking history and cannot be removed. Publish an empty layout instead to stop new bookings.', $f['name']));
        }
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM floors') <= 1) {
            throw new LayoutException('The building needs at least one floor.');
        }
        $this->db->transaction(function () use ($floorId): void {
            $this->db->execute('DELETE FROM layout_versions WHERE floor_id = ?', [$floorId]);
            $this->db->execute('DELETE FROM floors WHERE id = ?', [$floorId]);
        });
        $this->photos->deleteIfUploaded($f['photo_path']);
        $this->audit->record('floor.delete', 'floor', $floorId, ['name' => $f['name'], 'code' => $f['code']], null);
    }
}
