<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;

/**
 * Facility master (spec 5.3) and their placements on the floor plans.
 * Prices/stock for add-ons are placeholders pending K-DISC confirmation.
 */
final class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $facilities = [
            // code, name, description, icon, emoji, kind, unit, price, stock
            ['WIFI', 'High-speed Wi-Fi', 'Fibre broadband with backup link', 'wifi', '📶', 'included', null, 0, null],
            ['AC', 'Air conditioning', 'Climate-controlled work areas', 'snowflake', '❄️', 'included', null, 0, null],
            ['POWER', 'Power backup', 'UPS and generator backup at every desk', 'plug', '🔌', 'included', null, 0, null],
            ['PANTRY', 'Pantry access', 'Tea, coffee and drinking water', 'coffee', '☕', 'included', null, 0, null],
            ['RESTROOM', 'Restrooms', 'Clean restrooms on every floor', 'toilet', '🚻', 'included', null, 0, null],
            ['LOCKER', 'Personal locker', 'Lockable storage near your desk', 'lock', '🔒', 'addon', 'month', 300, 40],
            ['PARKING', 'Parking slot', 'Reserved two/four-wheeler parking', 'circle-parking', '🅿️', 'addon', 'month', 500, 20],
            ['PRINTING', 'Printing pack', '100 pages B/W printing & scanning', 'printer', '🖨️', 'addon', 'use', 200, null],
            ['PROJECTOR', 'Projector', 'Full-HD projector & screen for the conference room', 'projector', '📽️', 'addon', 'hour', 200, 1],
            ['MAIL', 'Mail handling', 'Use the centre address for your mail & parcels', 'package', '📦', 'addon', 'month', 250, null],
            ['ENTRY', 'Entry', 'Main entrance & reception', 'door-open', '🚪', 'landmark', null, 0, null],
            ['LIFT', 'Lift', 'Lift to all floors', 'arrow-up-down', '🛗', 'landmark', null, 0, null],
            ['FIRE_EXIT', 'Fire exit', 'Emergency exit & extinguisher', 'fire-extinguisher', '🧯', 'landmark', null, 0, null],
            ['ACCESSIBLE', 'Accessible route', 'Step-free access', 'accessibility', '♿', 'landmark', null, 0, null],
        ];
        foreach ($facilities as $i => [$code, $name, $desc, $icon, $emoji, $kind, $unit, $price, $stock]) {
            $this->db->execute(
                'INSERT INTO facilities (code, name, description, icon, emoji, kind, unit, price, gst_rate, stock_qty, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 18, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), icon = VALUES(icon), emoji = VALUES(emoji)',
                [$code, $name, $desc, $icon, $emoji, $kind, $unit, $price, $stock, $i],
            );
        }

        if ((int) $this->db->scalar('SELECT COUNT(*) FROM facility_placements') > 0) {
            return;
        }

        $db = $this->db;
        $f = [];
        foreach ($this->db->select('SELECT id, code FROM facilities') as $row) {
            $f[(string) $row['code']] = (int) $row['id'];
        }
        $floor = fn (string $slug): int => (int) $this->db->scalar('SELECT id FROM floors WHERE slug = ?', [$slug]);
        $zone = fn (string $slug, string $code): int => (int) $this->db->scalar(
            "SELECT z.id FROM zones z JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             JOIN floors f ON f.id = lv.floor_id WHERE f.slug = ? AND z.code = ?",
            [$slug, $code],
        );
        $seat = fn (string $code): int => (int) $this->db->scalar('SELECT id FROM seats WHERE code = ?', [$code]);

        $placements = [
            // Ground floor
            ['WIFI', 'zone', $zone('ground-floor', 'OPEN'), 5.0, 46.5],
            ['AC', 'zone', $zone('ground-floor', 'OPEN'), 8.0, 46.5],
            ['POWER', 'zone', $zone('ground-floor', 'OPEN'), 11.0, 46.5],
            ['WIFI', 'zone', $zone('ground-floor', 'ENCL'), 48.0, 52.5],
            ['AC', 'zone', $zone('ground-floor', 'ENCL'), 51.0, 52.5],
            ['POWER', 'zone', $zone('ground-floor', 'ENCL'), 54.0, 52.5],
            ['LOCKER', 'zone', $zone('ground-floor', 'ENCL'), 57.0, 52.5],
            ['PROJECTOR', 'seat', $seat('G-CF-F'), 71.0, 64.0],
            ['PANTRY', 'floor', $floor('ground-floor'), 81.0, 69.0],
            ['RESTROOM', 'floor', $floor('ground-floor'), 92.0, 69.0],
            ['LIFT', 'floor', $floor('ground-floor'), 81.0, 86.0],
            ['PRINTING', 'floor', $floor('ground-floor'), 92.0, 86.0],
            ['ENTRY', 'floor', $floor('ground-floor'), 44.5, 96.0],
            ['ACCESSIBLE', 'floor', $floor('ground-floor'), 47.5, 96.0],
            ['MAIL', 'floor', $floor('ground-floor'), 41.5, 96.0],
            ['PARKING', 'floor', $floor('ground-floor'), 4.0, 96.0],
            ['FIRE_EXIT', 'floor', $floor('ground-floor'), 97.0, 57.0],
            // First floor
            ['WIFI', 'zone', $zone('first-floor', 'OPEN'), 5.0, 96.0],
            ['AC', 'zone', $zone('first-floor', 'OPEN'), 8.0, 96.0],
            ['POWER', 'zone', $zone('first-floor', 'OPEN'), 11.0, 96.0],
            ['WIFI', 'zone', $zone('first-floor', 'ENCL'), 44.0, 75.0],
            ['AC', 'zone', $zone('first-floor', 'ENCL'), 47.0, 75.0],
            ['POWER', 'zone', $zone('first-floor', 'ENCL'), 50.0, 75.0],
            ['LOCKER', 'zone', $zone('first-floor', 'ENCL'), 53.0, 75.0],
            ['PANTRY', 'floor', $floor('first-floor'), 52.0, 87.0],
            ['RESTROOM', 'floor', $floor('first-floor'), 66.0, 87.0],
            ['LIFT', 'floor', $floor('first-floor'), 80.0, 87.0],
            ['FIRE_EXIT', 'floor', $floor('first-floor'), 93.0, 87.0],
        ];
        $version = static function (string $scope, int $scopeId) use ($db): int {
            return (int) match ($scope) {
                'zone' => $db->scalar('SELECT layout_version_id FROM zones WHERE id = ?', [$scopeId]),
                'seat' => $db->scalar('SELECT z.layout_version_id FROM seats s JOIN zones z ON z.id = s.zone_id WHERE s.id = ?', [$scopeId]),
                default => $db->scalar("SELECT id FROM layout_versions WHERE floor_id = ? AND status = 'published'", [$scopeId]),
            };
        };
        foreach ($placements as [$code, $scope, $scopeId, $x, $y]) {
            $this->db->insert('facility_placements', [
                'layout_version_id' => $version($scope, $scopeId),
                'facility_id' => $f[$code], 'scope' => $scope, 'scope_id' => $scopeId, 'x_pct' => $x, 'y_pct' => $y,
            ]);
        }
    }
}
