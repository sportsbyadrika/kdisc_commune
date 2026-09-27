<?php

/**
 * Regenerates the placeholder floor-plan images public/media/floor-*.svg from the
 * seed layout (database/seeds/data/kottarakara_layout.php), so the drawn desks and
 * rooms line up with the seeded seat coordinates.
 *
 *   php bin/make-placeholder-plans.php
 *
 * Real floor photos / CAD plans uploaded later through the Layout Designer replace these.
 */

declare(strict_types=1);

use App\Services\Space\LayoutBlueprint;

require dirname(__DIR__) . '/vendor/autoload.php';

const W = 1600;
const H = 1000;

$data = require dirname(__DIR__) . '/database/seeds/data/kottarakara_layout.php';

function px(float $pct, bool $horizontal = true): string
{
    return rtrim(rtrim(number_format($pct * ($horizontal ? W : H) / 100, 1, '.', ''), '0'), '.');
}

/** @param array{x: float, y: float, w: float, h: float} $r */
function rect(array $r, string $attrs, float $rx = 0): string
{
    return sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" %s/>', px($r['x']), px($r['y'], false), px($r['w']), px($r['h'], false), $rx, $attrs);
}

function label(float $x, float $y, string $text, string $cls = 'lbl'): string
{
    return sprintf('<text x="%s" y="%s" class="%s">%s</text>', px($x), px($y, false), $cls, htmlspecialchars($text, ENT_XML1));
}

/** Pantry counter, restroom block, lift and stairs drawn to match the facility placements in FacilitySeeder. @param array{x: float, y: float, w: float, h: float} $r */
function serviceArea(string $floorCode, array $r): string
{
    $o = [];
    $lift = static function (float $x, float $y) use (&$o): void {
        $box = ['x' => $x, 'y' => $y, 'w' => 5.2, 'h' => 8];
        $o[] = rect($box, 'fill="#e2e8f0" stroke="#475569" stroke-width="3"');
        $o[] = sprintf('<path d="M%s %s L%s %s M%s %s L%s %s" stroke="#94a3b8" stroke-width="2"/>', px($x), px($y, false), px($x + 5.2), px($y + 8, false), px($x + 5.2), px($y, false), px($x), px($y + 8, false));
    };
    if ($floorCode === 'G') {
        $o[] = rect(['x' => $r['x'] + 1.2, 'y' => $r['y'] + 5.5, 'w' => 7, 'h' => 2.6], 'class="desk"', 3);      // pantry counter
        $o[] = rect(['x' => $r['x'] + 11, 'y' => $r['y'] + 4.5, 'w' => 8, 'h' => 13], 'fill="#ffffff" fill-opacity=".6" class="iwall" stroke-width="3"'); // restroom
        $lift($r['x'] + 1.2, $r['y'] + 21.5);
    } else {
        $o[] = rect(['x' => $r['x'] + 5, 'y' => $r['y'] + 4, 'w' => 8, 'h' => 2.6], 'class="desk"', 3);
        $o[] = rect(['x' => $r['x'] + 19, 'y' => $r['y'] + 2.5, 'w' => 11, 'h' => 10], 'fill="#ffffff" fill-opacity=".6" class="iwall" stroke-width="3"');
        $lift($r['x'] + 35.5, $r['y'] + 3);
        $o[] = rect(['x' => $r['x'] + 45, 'y' => $r['y'] + 2.5, 'w' => 8.5, 'h' => 10], 'fill="url(#stairs)" stroke="#64748b" stroke-width="2"');
    }
    return implode("\n", $o);
}

$tints = ['FLEXI' => '#22c55e', 'DEDICATED' => '#3b82f6', 'CABIN' => '#8b5cf6', 'CONF' => '#f59e0b'];

foreach ($data['floors'] as $floor) {
    $zones = LayoutBlueprint::expand($floor);
    $out = [];
    $out[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-label="%s plan (placeholder)">', W, H, W, H, htmlspecialchars($floor['name']));
    $out[] = '<defs>
  <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M40 0H0V40" fill="none" stroke="#e2e8f0" stroke-width="1"/></pattern>
  <pattern id="tiles" width="16" height="16" patternUnits="userSpaceOnUse"><rect width="16" height="16" fill="#f1f5f9"/><path d="M16 0H0V16" fill="none" stroke="#e2e8f0" stroke-width="1"/></pattern>
  <pattern id="stairs" width="14" height="14" patternUnits="userSpaceOnUse"><path d="M0 7H14" stroke="#94a3b8" stroke-width="2"/></pattern>
  <filter id="soft" x="-5%" y="-5%" width="110%" height="110%"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="#0f172a" flood-opacity=".12"/></filter>
  <style>
    .lbl{font:700 17px system-ui,-apple-system,"Segoe UI",sans-serif;fill:#334155;letter-spacing:.08em;text-transform:uppercase}
    .sub{font:500 14px system-ui,-apple-system,"Segoe UI",sans-serif;fill:#64748b}
    .wall{fill:none;stroke:#1e293b;stroke-width:12;stroke-linejoin:round}
    .iwall{fill:none;stroke:#475569;stroke-width:6}
    .desk{fill:#e7e5e4;stroke:#a8a29e;stroke-width:1.5}
    .chair{fill:#ffffff;stroke:#cbd5e1;stroke-width:2}
    .door{fill:none;stroke:#94a3b8;stroke-width:2;stroke-dasharray:4 4}
    .plant{fill:#86efac;stroke:#16a34a;stroke-width:2}
  </style>
</defs>';
    $out[] = '<rect width="1600" height="1000" fill="#f8fafc"/><rect width="1600" height="1000" fill="url(#grid)"/>';
    // Building shell
    $out[] = '<rect x="32" y="30" width="1536" height="940" fill="#ffffff" filter="url(#soft)"/>';

    foreach ($zones as $zone) {
        $r = $zone['rect'];
        $tint = $tints[$zone['category'] ?? ''] ?? '#94a3b8';
        if ($zone['kind'] === 'service') {
            $out[] = rect($r, 'fill="url(#tiles)"');
        } else {
            $out[] = rect($r, sprintf('fill="%s" fill-opacity=".07"', $tint), 6);
        }
        $enclosed = $zone['category'] !== 'FLEXI';
        $out[] = rect($r, $enclosed ? 'class="iwall"' : 'fill="none" stroke="#cbd5e1" stroke-width="3" stroke-dasharray="10 8"', $enclosed ? 0 : 6);
        if ($enclosed) {
            // Door gap + swing on the wall facing the corridor, at the end away from the label.
            $doorW = 2.8;
            $onBottom = $r['y'] < 30;
            $doorX = $zone['kind'] === 'service' ? $r['x'] + 16 : $r['x'] + $r['w'] - $doorW - 1.2;
            $doorY = $onBottom ? $r['y'] + $r['h'] : $r['y'];
            $radius = $doorW * W / 100;
            $out[] = sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#ffffff" stroke-width="10"/>', px($doorX), px($doorY, false), px($doorX + $doorW), px($doorY, false));
            $endY = (float) px($doorY, false) + ($onBottom ? -$radius : $radius);
            $out[] = sprintf('<path d="M%s %s A%.1f %.1f 0 0 %d %s %.1f" class="door"/>', px($doorX + $doorW), px($doorY, false), $radius, $radius, $onBottom ? 0 : 1, px($doorX), $endY);
        }
        $out[] = label($r['x'] + 1.2, $r['y'] + 3.0, $zone['name']);

        foreach ($zone['seats'] as $seat) {
            if ($zone['kind'] === 'grid') {
                continue;
            }
            if ($zone['kind'] === 'cabin') {
                $chairs = $seat['children'];
                $first = $chairs[0];
                $last = $chairs[count($chairs) - 1];
                $out[] = rect(['x' => $first['x'] - 1.0, 'y' => $first['y'] - 6.2, 'w' => $last['x'] + $last['w'] - $first['x'] + 2.0, 'h' => 5.2], 'class="desk"', 6);
                $out[] = sprintf('<circle cx="%s" cy="%s" r="14" class="plant"/>', px($r['x'] + $r['w'] - 2.2), px($r['y'] + $r['h'] - 4, false));
            }
            if ($zone['kind'] === 'room') {
                $t = LayoutBlueprint::conferenceTable($r);
                $out[] = rect($t, 'class="desk"', 40);
                $out[] = rect(['x' => $r['x'] + $r['w'] - 1.8, 'y' => $r['y'] + 8, 'w' => 0.8, 'h' => $r['h'] - 16], 'fill="#e2e8f0" stroke="#94a3b8" stroke-width="2"');
                $out[] = label($r['x'] + 1.2, $r['y'] + 5.6, '9 seats · projector', 'sub');
            }
            foreach ($seat['children'] ?? [] as $c) {
                $out[] = rect($c, 'class="chair"', 8);
            }
        }

        if ($zone['kind'] === 'grid') {
            // one long desk per row, chairs below it
            $rows = [];
            foreach ($zone['seats'] as $s) {
                $rows[$s['row']][] = $s;
            }
            foreach ($rows as $row) {
                $first = $row[0];
                $last = $row[count($row) - 1];
                $deskH = $zone['rows'] > 6 ? 1.9 : 3.2;
                $out[] = rect(['x' => $first['x'] - 0.9, 'y' => $first['y'] - $deskH - 0.5, 'w' => $last['x'] + $last['w'] - $first['x'] + 1.8, 'h' => $deskH], 'class="desk"', 4);
                foreach ($row as $s) {
                    $out[] = rect($s, 'class="chair"', 8);
                }
            }
        }

        if ($zone['kind'] === 'service') {
            $out[] = serviceArea($floor['code'], $r);
        }
    }

    // Outer wall with window strips and the main entry
    $out[] = '<rect x="32" y="30" width="1536" height="940" class="wall"/>';
    foreach ([[120, 30, 420, 30], [760, 30, 1500, 30], [32, 120, 32, 440], [1568, 120, 1568, 420]] as [$x1, $y1, $x2, $y2]) {
        $out[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#7dd3fc" stroke-width="6"/>', $x1, $y1, $x2, $y2);
    }
    if ($floor['code'] === 'G') {
        $out[] = '<line x1="672" y1="970" x2="760" y2="970" stroke="#ffffff" stroke-width="16"/>';
        $out[] = '<path d="M716 996 L716 948 M700 962 L716 946 L732 962" stroke="#0f172a" stroke-width="4" fill="none" stroke-linecap="round"/>';
    }
    $out[] = sprintf('<text x="1560" y="22" class="sub" text-anchor="end">%s · placeholder plan — replace with the real floor photo</text>', htmlspecialchars($floor['name']));
    $out[] = '</svg>';

    $file = dirname(__DIR__) . '/public/' . $floor['photo_path'];
    file_put_contents($file, implode("\n", $out) . "\n");
    echo 'Wrote ' . $floor['photo_path'] . PHP_EOL;
}
