<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;
use App\Services\Space\LayoutBlueprint;

/**
 * Published layout v1 for each floor: zones and all seats from spec 7.1
 * (G: 18 flexi, 32 dedicated, cabins B/D/E x3 chairs, conference F x9; F: 24 flexi, 64 dedicated).
 * Skips floors that already have a layout version.
 */
final class LayoutSeeder extends Seeder
{
    public function run(): void
    {
        $data = require __DIR__ . '/data/kottarakara_layout.php';
        $categories = [];
        foreach ($this->db->select('SELECT id, code, colour FROM seat_categories') as $row) {
            $categories[(string) $row['code']] = $row;
        }
        $managerId = $this->db->scalar("SELECT id FROM staff_users WHERE role = 'centre_manager' ORDER BY id LIMIT 1");

        foreach ($data['floors'] as $floor) {
            $floorId = (int) $this->db->scalar('SELECT id FROM floors WHERE slug = ?', [$floor['slug']]);
            if ($this->db->scalar('SELECT id FROM layout_versions WHERE floor_id = ?', [$floorId]) !== null) {
                continue;
            }
            $this->db->transaction(function () use ($floor, $floorId, $categories, $managerId): void {
                $versionId = $this->db->insert('layout_versions', [
                    'floor_id' => $floorId, 'version_no' => 1, 'status' => 'published',
                    'notes' => 'Initial seeded layout', 'created_by' => $managerId,
                    'published_by' => $managerId, 'published_at' => date('Y-m-d H:i:s'),
                ]);
                $seatCount = 0;
                foreach (LayoutBlueprint::expand($floor) as $order => $zone) {
                    $category = $zone['category'] !== null ? $categories[$zone['category']] : null;
                    $zoneId = $this->db->insert('zones', [
                        'layout_version_id' => $versionId,
                        'seat_category_id' => $category['id'] ?? null,
                        'code' => $zone['code'],
                        'name' => $zone['name'],
                        'polygon' => null,
                        'x_pct' => $zone['rect']['x'], 'y_pct' => $zone['rect']['y'],
                        'w_pct' => $zone['rect']['w'], 'h_pct' => $zone['rect']['h'],
                        'colour' => $category['colour'] ?? '#94a3b8',
                        'sort_order' => $order,
                    ]);
                    foreach ($zone['seats'] as $seat) {
                        $parentId = $this->insertSeat($zoneId, $seat, null);
                        $seatCount++;
                        foreach ($seat['children'] ?? [] as $child) {
                            $this->insertSeat($zoneId, $child, $parentId);
                            $seatCount++;
                        }
                    }
                }
                $this->info(sprintf('          %s: %d seat rows', $floor['name'], $seatCount));
            });
        }
    }

    /** @param array<string, mixed> $seat */
    private function insertSeat(int $zoneId, array $seat, ?int $parentId): int
    {
        return $this->db->insert('seats', [
            'zone_id' => $zoneId,
            'parent_id' => $parentId,
            'code' => $seat['code'],
            'label' => $seat['label'],
            'kind' => $seat['kind'],
            'capacity' => $seat['capacity'],
            'x_pct' => $seat['x'], 'y_pct' => $seat['y'], 'w_pct' => $seat['w'], 'h_pct' => $seat['h'],
            'rotation' => 0,
            'status' => 'available',
        ]);
    }
}
