<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;

/** Kottarakara centre (code KTR), its building and floors (with placeholder SVG imagery). */
final class CentreSeeder extends Seeder
{
    public function run(): void
    {
        $data = require __DIR__ . '/data/kottarakara_layout.php';

        $this->db->execute(
            "INSERT INTO centres (code, name, address, city, district, state, state_code, pincode, phone, email)
             VALUES ('KTR', 'Commune Kottarakara', 'Commune Workspace, Kottarakara', 'Kottarakara', 'Kollam', 'Kerala', '32', '691506', '+91 474 000 0000', 'commune.ktr@kdisc.kerala.gov.in')
             ON DUPLICATE KEY UPDATE name = VALUES(name)",
        );
        $centreId = (int) $this->db->scalar("SELECT id FROM centres WHERE code = 'KTR'");

        $buildingId = (int) $this->db->scalar('SELECT id FROM buildings WHERE centre_id = ? LIMIT 1', [$centreId]);
        if ($buildingId === 0) {
            $buildingId = $this->db->insert('buildings', [
                'centre_id' => $centreId,
                'name' => $data['building']['name'],
                'description' => 'A two-storey "Work Near Home" workspace by K-DISC with open flexi desks, enclosed dedicated seats, executive cabins and a conference room.',
                'photo_path' => $data['building']['photo_path'],
                'photo_w' => $data['building']['photo_w'],
                'photo_h' => $data['building']['photo_h'],
            ]);
        }

        foreach ($data['floors'] as $i => $floor) {
            $exists = $this->db->scalar('SELECT id FROM floors WHERE building_id = ? AND slug = ?', [$buildingId, $floor['slug']]);
            if ($exists !== null) {
                continue;
            }
            $this->db->insert('floors', [
                'building_id' => $buildingId,
                'name' => $floor['name'],
                'slug' => $floor['slug'],
                'code' => $floor['code'],
                'level' => $floor['level'],
                'photo_path' => $floor['photo_path'],
                'photo_w' => $floor['photo_w'],
                'photo_h' => $floor['photo_h'],
                'hotspot_polygon' => json_encode($floor['hotspot']),
                'sort_order' => $i,
            ]);
        }
    }
}
