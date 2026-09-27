<?php

/**
 * Seed layout for the Kottarakara centre (spec 7.1). Shared by LayoutSeeder and
 * bin/make-placeholder-plans.php (which draws public/media/floor-*.svg from it),
 * so the placeholder plan images always match the seeded seat coordinates.
 *
 * Rects are percentages of a 1600x1000 floor image (x/y = top-left).
 * Seat grids are computed with App\Services\Space\GridLayout::grid().
 *
 * zone.kind: 'grid' (rows x cols of seats) | 'cabin' (1 parent + N chairs) | 'room' (1 parent + N chairs) | 'service'
 */

declare(strict_types=1);

return [
    'building' => [
        'name' => 'Commune Kottarakara',
        'photo_path' => 'media/building.svg',
        'photo_w' => 1600,
        'photo_h' => 1000,
    ],
    'floors' => [
        [
            'name' => 'Ground Floor', 'slug' => 'ground-floor', 'code' => 'G', 'level' => 0,
            'photo_path' => 'media/floor-ground.svg', 'photo_w' => 1600, 'photo_h' => 1000,
            // Band on building.svg (percent polygon)
            'hotspot' => [[14, 58], [86, 58], [86, 89], [14, 89]],
            'zones' => [
                ['code' => 'OPEN', 'name' => 'Open Area', 'category' => 'FLEXI', 'kind' => 'grid',
                    'rect' => ['x' => 3, 'y' => 5, 'w' => 40, 'h' => 44], 'rows' => 3, 'cols' => 6],
                ['code' => 'ENCL', 'name' => 'Enclosed Room', 'category' => 'DEDICATED', 'kind' => 'grid',
                    'rect' => ['x' => 46, 'y' => 5, 'w' => 51, 'h' => 50], 'rows' => 4, 'cols' => 8],
                ['code' => 'CAB-B', 'name' => 'Cabin B', 'category' => 'CABIN', 'kind' => 'cabin', 'letter' => 'B', 'chairs' => 3,
                    'rect' => ['x' => 3, 'y' => 55, 'w' => 12.5, 'h' => 39]],
                ['code' => 'CAB-D', 'name' => 'Cabin D', 'category' => 'CABIN', 'kind' => 'cabin', 'letter' => 'D', 'chairs' => 3,
                    'rect' => ['x' => 16.75, 'y' => 55, 'w' => 12.5, 'h' => 39]],
                ['code' => 'CAB-E', 'name' => 'Cabin E', 'category' => 'CABIN', 'kind' => 'cabin', 'letter' => 'E', 'chairs' => 3,
                    'rect' => ['x' => 30.5, 'y' => 55, 'w' => 12.5, 'h' => 39]],
                ['code' => 'CONF-F', 'name' => 'Conference Room F', 'category' => 'CONF', 'kind' => 'room', 'letter' => 'F', 'chairs' => 9,
                    'rect' => ['x' => 46, 'y' => 60, 'w' => 28, 'h' => 34]],
                ['code' => 'SVC', 'name' => 'Pantry & services', 'category' => null, 'kind' => 'service',
                    'rect' => ['x' => 77, 'y' => 60, 'w' => 20, 'h' => 34]],
            ],
        ],
        [
            'name' => 'First Floor', 'slug' => 'first-floor', 'code' => 'F', 'level' => 1,
            'photo_path' => 'media/floor-first.svg', 'photo_w' => 1600, 'photo_h' => 1000,
            'hotspot' => [[14, 27], [86, 27], [86, 58], [14, 58]],
            'zones' => [
                ['code' => 'OPEN', 'name' => 'Open Area', 'category' => 'FLEXI', 'kind' => 'grid',
                    'rect' => ['x' => 3, 'y' => 5, 'w' => 36, 'h' => 89], 'rows' => 6, 'cols' => 4],
                ['code' => 'ENCL', 'name' => 'Enclosed Room', 'category' => 'DEDICATED', 'kind' => 'grid',
                    'rect' => ['x' => 42, 'y' => 5, 'w' => 55, 'h' => 72], 'rows' => 8, 'cols' => 8, 'seat_w' => 3.0],
                ['code' => 'SVC', 'name' => 'Pantry & services', 'category' => null, 'kind' => 'service',
                    'rect' => ['x' => 42, 'y' => 80, 'w' => 55, 'h' => 14]],
            ],
        ],
    ],
];
