<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Core\Database;
use App\Enums\SeatCategory;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\QuoteService;
use App\Support\Clock;

/**
 * JSON payload for the Space Explorer floor map (GET /api/space/floors/{floor}/map).
 * All geometry is in PERCENT of the floor image (x/y = top-left, w/h = size); the client scales to the
 * image's pixel size (floor.width/height) in an SVG viewBox.
 */
final class FloorMapService
{
    public function __construct(
        private readonly Database $db,
        private readonly AvailabilityService $availability,
        private readonly PriceResolver $prices,
        private readonly QuoteService $quotes,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findFloor(string $slugOrId): ?array
    {
        return ctype_digit($slugOrId)
            ? $this->db->first('SELECT * FROM floors WHERE id = ?', [(int) $slugOrId])
            : $this->db->first('SELECT * FROM floors WHERE slug = ?', [$slugOrId]);
    }

    /** @return list<array<string, mixed>> */
    public function floors(): array
    {
        return $this->db->select('SELECT id, slug, name, code, level FROM floors ORDER BY sort_order, level');
    }

    /**
     * @param array<string, mixed> $floor
     * @return array<string, mixed>
     */
    public function map(array $floor, BookingPeriod $period, ?SeatHolder $me = null, bool $withOccupants = false, ?int $versionId = null): array
    {
        $floorId = (int) $floor['id'];
        $versionId ??= $this->publishedVersionId($floorId);
        $seats = $this->availability->floorSeats($floorId, $versionId);
        $statuses = $this->availability->floorStatuses($floorId, $period, $me, $versionId);
        $rates = $this->prices->forSeats(array_map(static fn (array $s) => (int) $s['id'], array_filter($seats, static fn (array $s) => $s['parent_id'] === null)), $period->from);

        $zones = [];
        foreach ($this->db->select(
            "SELECT z.*, sc.code AS category FROM zones z
             LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id WHERE z.layout_version_id = ? AND z.code NOT LIKE '~%' ORDER BY z.sort_order",
            [$versionId],
        ) as $z) {
            $zones[(int) $z['id']] = [
                'id' => (int) $z['id'],
                'code' => (string) $z['code'],
                'name' => (string) $z['name'],
                'category' => $z['category'],
                'colour' => $z['colour'],
                'rect' => ['x' => (float) $z['x_pct'], 'y' => (float) $z['y_pct'], 'w' => (float) $z['w_pct'], 'h' => (float) $z['h_pct']],
                'polygon' => $z['polygon'] !== null ? json_decode((string) $z['polygon'], true) : GridLayout::rectToPolygon(['x' => (float) $z['x_pct'], 'y' => (float) $z['y_pct'], 'w' => (float) $z['w_pct'], 'h' => (float) $z['h_pct']]),
                'units' => 0,
                'free' => 0,
                'included' => [],
            ];
        }

        // Facilities placed on this layout version: floor-, zone- and seat-scoped (scope_id = row id in the version).
        $facilities = [];
        $includedBy = ['floor' => [], 'zone' => [], 'seat' => []];
        $placements = $this->db->select(
            'SELECT fp.id AS placement_id, fp.scope, fp.scope_id, fp.x_pct, fp.y_pct, fp.note, f.id, f.code, f.name, f.description, f.icon, f.emoji, f.kind, f.unit, f.price
             FROM facility_placements fp JOIN facilities f ON f.id = fp.facility_id AND f.is_active = 1
             WHERE fp.layout_version_id = ?
             ORDER BY f.sort_order, fp.id',
            [$versionId],
        );
        foreach ($placements as $p) {
            if ($p['x_pct'] !== null) {
                $facilities[] = [
                    'id' => (int) $p['placement_id'],
                    'facility_id' => (int) $p['id'],
                    'code' => (string) $p['code'],
                    'name' => (string) $p['name'],
                    'description' => (string) ($p['description'] ?? ''),
                    'icon' => $p['icon'],
                    'emoji' => $p['emoji'],
                    'kind' => (string) $p['kind'],
                    'unit' => $p['unit'],
                    'price' => (float) $p['price'],
                    'scope' => (string) $p['scope'],
                    'x' => (float) $p['x_pct'],
                    'y' => (float) $p['y_pct'],
                ];
            }
            if ($p['kind'] === 'included') {
                $includedBy[(string) $p['scope']][(int) $p['scope_id']][(string) $p['code']] = ['code' => (string) $p['code'], 'name' => (string) $p['name'], 'icon' => $p['icon'], 'emoji' => $p['emoji']];
            }
        }
        // Included facilities (Wi-Fi, AC, power, pantry, restrooms) come with every seat (spec 5.3); placements only
        // decide where their icons are drawn. Seat-/zone-scoped included items are listed first.
        $floorIncluded = $includedBy['floor'][$floorId] ?? [];
        foreach ($this->db->select("SELECT code, name, icon, emoji FROM facilities WHERE kind = 'included' AND is_active = 1 ORDER BY sort_order") as $f) {
            $floorIncluded[(string) $f['code']] ??= ['code' => (string) $f['code'], 'name' => (string) $f['name'], 'icon' => $f['icon'], 'emoji' => $f['emoji']];
        }
        foreach ($zones as $id => &$zone) {
            $zone['included'] = array_values(($includedBy['zone'][$id] ?? []) + $floorIncluded);
        }
        unset($zone);

        $occupants = $withOccupants ? $this->availability->occupants($floorId, $period, $versionId) : [];
        // reception: who is checked in right now (matched by seat_key — stable across layout versions)
        $checkedIn = [];
        if ($withOccupants) {
            foreach ($this->db->select('SELECT seat_key, booking_id, checked_in_at FROM checkins WHERE checked_out_at IS NULL') as $c) {
                $checkedIn[(int) $c['seat_key']] = $c;
            }
        }
        $today = $this->clock->today();
        $outSeats = [];
        $hourlyUnits = [];
        foreach ($seats as $s) {
            $id = (int) $s['id'];
            $isUnit = $s['parent_id'] === null;
            $category = SeatCategory::tryFrom((string) ($s['category'] ?? ''));
            $zoneId = (int) $s['zone_id'];
            $unitRates = [];
            foreach ($rates[$id] ?? [] as $unit => $r) {
                if ($category !== null && in_array($unit, array_map(static fn ($u) => $u->value, $category->billingUnits()), true)) {
                    $unitRates[$unit] = $r['amount'];
                }
            }
            $row = [
                'id' => $id,
                'key' => (int) ($s['seat_key'] ?? $id),
                'code' => (string) $s['code'],
                'label' => (string) ($s['label'] ?? $s['code']),
                'kind' => (string) $s['kind'],
                'parent' => $isUnit ? null : (int) $s['parent_id'],
                'zone' => $zoneId,
                'category' => $category?->value,
                'capacity' => (int) $s['capacity'],
                'x' => (float) $s['x_pct'],
                'y' => (float) $s['y_pct'],
                'w' => (float) $s['w_pct'],
                'h' => (float) $s['h_pct'],
                'rotation' => (int) $s['rotation'],
                'status' => $statuses[$id] ?? AvailabilityService::BLOCKED,
                'tags' => $s['tags'] !== null && $s['tags'] !== '' ? explode(',', (string) $s['tags']) : [],
            ];
            if ($isUnit) {
                $row['rates'] = $unitRates;
                $row['included'] = array_values(($includedBy['seat'][$id] ?? []) + ($includedBy['zone'][$zoneId] ?? []) + $floorIncluded);
                if (isset($occupants[$id])) {
                    $o = $occupants[$id];
                    $row['occupant'] = [
                        'name' => $o['customer_name'], 'unique_id' => $o['unique_id'], 'booking_no' => $o['booking_no'],
                        'status' => $o['status'], 'from' => $o['start_date'], 'to' => $o['end_date'],
                        'start_time' => $o['start_time'] !== null ? substr((string) $o['start_time'], 0, 5) : null,
                        'end_time' => $o['end_time'] !== null ? substr((string) $o['end_time'], 0, 5) : null,
                        'checked_in' => isset($checkedIn[(int) ($s['seat_key'] ?? $id)]) && (int) $checkedIn[(int) ($s['seat_key'] ?? $id)]['booking_id'] === (int) $o['booking_id'],
                        'checked_in_at' => $checkedIn[(int) ($s['seat_key'] ?? $id)]['checked_in_at'] ?? null,
                        'can_check' => in_array($o['status'], ['confirmed', 'active'], true) && (string) $o['start_date'] <= $today && (string) $o['end_date'] >= $today && $o['start_time'] === null,
                    ];
                }
                if ($category?->hourlyOnly()) {
                    $hourlyUnits[] = $id;
                }
                if (isset($zones[$zoneId]) && $category !== null) {
                    $zones[$zoneId]['units']++;
                    $zones[$zoneId]['free'] += in_array($row['status'], [AvailabilityService::AVAILABLE, AvailabilityService::MINE], true) ? 1 : 0;
                }
            }
            $outSeats[] = $row;
        }

        $slots = [];
        foreach ($hourlyUnits as $unitId) {
            $slots[$unitId] = $this->availability->hourlySlots($unitId, $period->from, $me);
        }

        $statusOnly = [];
        foreach ($outSeats as $s) {
            $statusOnly[$s['id']] = $s['status'];
        }

        return [
            'floor' => [
                'id' => $floorId,
                'slug' => (string) $floor['slug'],
                'name' => (string) $floor['name'],
                'code' => (string) $floor['code'],
                'level' => (int) $floor['level'],
                'image' => media((string) $floor['photo_path']),
                'width' => (int) ($floor['photo_w'] ?: 1600),
                'height' => (int) ($floor['photo_h'] ?: 1000),
            ],
            'period' => $period->toArray(),
            'zones' => array_values($zones),
            'seats' => $outSeats,
            'facilities' => $facilities,
            'addons' => $this->quotes->addonCatalog($period->isHourly() ? BookingPeriod::days($period->from, $period->to) : $period),
            // hour/use add-ons for the conference room (stock checked over the opening hours of the first day)
            'addons_hourly' => $this->quotes->addonCatalog(BookingPeriod::hours(
                $period->from,
                sprintf('%02d:00', (int) setting('conference_open_hour', 8)),
                sprintf('%02d:00', (int) setting('conference_close_hour', 20)),
            )),
            'slots' => (object) $slots,
            'version' => self::version($statusOnly, $slots),
        ];
    }

    /** Published layout version of a floor (0 when the floor has none yet). */
    public function publishedVersionId(int $floorId): int
    {
        return (int) $this->db->scalar("SELECT id FROM layout_versions WHERE floor_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1", [$floorId]);
    }

    /**
     * Compact poll payload: seat id => status (+ version), or unchanged.
     *
     * @param array<string, mixed> $floor
     * @return array<string, mixed>
     */
    public function poll(array $floor, BookingPeriod $period, ?SeatHolder $me, ?string $since): array
    {
        $statuses = $this->availability->floorStatuses((int) $floor['id'], $period, $me);
        $slots = [];
        foreach ($this->db->column(
            "SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             JOIN seat_categories sc ON sc.id = z.seat_category_id WHERE lv.floor_id = ? AND sc.hourly_only = 1 AND s.parent_id IS NULL",
            [(int) $floor['id']],
        ) as $unitId) {
            $slots[(int) $unitId] = $this->availability->hourlySlots((int) $unitId, $period->from, $me);
        }
        $version = self::version($statuses, $slots);
        if ($since !== null && $since === $version) {
            return ['version' => $version, 'unchanged' => true];
        }
        return ['version' => $version, 'unchanged' => false, 'statuses' => (object) $statuses, 'slots' => (object) $slots];
    }

    /**
     * @param array<int, string> $statuses
     * @param array<int, list<array<string, string>>> $slots
     */
    private static function version(array $statuses, array $slots): string
    {
        ksort($slots);
        return substr(AvailabilityService::version($statuses) . hash('crc32b', (string) json_encode($slots)), 0, 24);
    }
}
