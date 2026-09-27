<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Enums\SeatCategory;

/**
 * Expands a declarative floor description (see database/seeds/data/kottarakara_layout.php)
 * into concrete seats with percentage coordinates and codes. Used by LayoutSeeder and by
 * bin/make-placeholder-plans.php so the placeholder floor images line up with the seats.
 *
 * Seat code format: {floorCode}-{segment}-{nn}  e.g. G-FX-03, F-DD-12
 *   cabin: G-CB-B (whole cabin) with chairs G-CB-B1..B3
 *   room:  G-CF-F (whole room)  with chairs G-CF-F1..F9
 */
final class LayoutBlueprint
{
    public const SEAT_W = 3.4;
    public const CHAIR_W = 2.6;

    /**
     * @param array<string, mixed> $floor
     * @return list<array<string, mixed>> zones, each with a 'seats' list; parents carry 'children'
     */
    public static function expand(array $floor): array
    {
        $zones = [];
        foreach ($floor['zones'] as $zone) {
            $rect = $zone['rect'];
            $category = $zone['category'] !== null ? SeatCategory::from($zone['category']) : null;
            $zone['seats'] = match ($zone['kind']) {
                'grid' => self::gridSeats($floor['code'], $category, $rect, (int) $zone['rows'], (int) $zone['cols'], (float) ($zone['seat_w'] ?? self::SEAT_W)),
                'cabin' => [self::cabin($floor['code'], $zone['letter'], $rect, (int) $zone['chairs'])],
                'room' => [self::room($floor['code'], $zone['letter'], $rect, (int) $zone['chairs'])],
                default => [],
            };
            $zones[] = $zone;
        }
        return $zones;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $rect
     * @return list<array<string, mixed>>
     */
    private static function gridSeats(string $floorCode, ?SeatCategory $category, array $rect, int $rows, int $cols, float $seatW): array
    {
        $segment = $category?->codeSegment() ?? 'ST';
        $seats = [];
        $seatH = round($seatW * GridLayout::ASPECT, 3);
        $cellH = ($rect['h'] - 6.0) / max(1, $rows);
        // Push each seat towards the bottom of its cell, leaving room for the desk drawn above it.
        $shift = max(0.0, min(2.2, ($cellH - $seatH) / 2 - 0.15));
        foreach (GridLayout::grid($rect, $rows, $cols, $seatW, $seatH, 1.5, 3.0) as $i => $g) {
            $g['y'] = round($g['y'] + $shift, 3);
            $seats[] = [
                'code' => sprintf('%s-%s-%02d', $floorCode, $segment, $i + 1),
                'label' => sprintf('%02d', $i + 1),
                'kind' => 'seat', 'capacity' => 1,
                'x' => $g['x'], 'y' => $g['y'], 'w' => $g['w'], 'h' => $g['h'],
                'row' => $g['row'], 'col' => $g['col'],
            ];
        }
        return $seats;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $rect
     * @return array<string, mixed>
     */
    private static function cabin(string $floorCode, string $letter, array $rect, int $chairs): array
    {
        $w = self::CHAIR_W;
        $h = round($w * GridLayout::ASPECT, 3);
        $gap = 0.8;
        $rowW = $chairs * $w + ($chairs - 1) * $gap;
        $startX = $rect['x'] + ($rect['w'] - $rowW) / 2;
        $y = $rect['y'] + $rect['h'] * 0.52;
        $children = [];
        foreach (GridLayout::row($startX, $y, $chairs, $w, $h, $gap) as $i => $c) {
            $children[] = ['code' => sprintf('%s-CB-%s%d', $floorCode, $letter, $i + 1), 'label' => $letter . ($i + 1), 'kind' => 'seat', 'capacity' => 1] + $c;
        }
        return [
            'code' => sprintf('%s-CB-%s', $floorCode, $letter), 'label' => 'Cabin ' . $letter, 'kind' => 'cabin', 'capacity' => $chairs,
            'x' => round($rect['x'] + 0.8, 3), 'y' => round($rect['y'] + 1.4, 3), 'w' => round($rect['w'] - 1.6, 3), 'h' => round($rect['h'] - 2.8, 3),
            'children' => $children,
        ];
    }

    /**
     * Conference table with chairs on both long sides and one at the head.
     * @param array{x: float, y: float, w: float, h: float} $rect
     * @return array<string, mixed>
     */
    private static function room(string $floorCode, string $letter, array $rect, int $chairs): array
    {
        $table = self::conferenceTable($rect);
        $w = self::CHAIR_W;
        $h = round($w * GridLayout::ASPECT, 3);
        $perSide = intdiv($chairs - 1, 2);
        $cell = $table['w'] / max(1, $perSide);
        $positions = [];
        for ($i = 0; $i < $perSide; $i++) {
            $positions[] = [$table['x'] + $i * $cell + ($cell - $w) / 2, $table['y'] - $h - 0.8];
        }
        for ($i = 0; $i < $perSide; $i++) {
            $positions[] = [$table['x'] + $i * $cell + ($cell - $w) / 2, $table['y'] + $table['h'] + 0.8];
        }
        while (count($positions) < $chairs) {
            $positions[] = [$table['x'] - $w - 0.8, $table['y'] + ($table['h'] - $h) / 2];
        }
        $children = [];
        foreach ($positions as $i => [$x, $y]) {
            $children[] = [
                'code' => sprintf('%s-CF-%s%d', $floorCode, $letter, $i + 1), 'label' => $letter . ($i + 1), 'kind' => 'seat', 'capacity' => 1,
                'x' => round($x, 3), 'y' => round($y, 3), 'w' => $w, 'h' => $h,
            ];
        }
        return [
            'code' => sprintf('%s-CF-%s', $floorCode, $letter), 'label' => 'Conference ' . $letter, 'kind' => 'room', 'capacity' => $chairs,
            'x' => round($rect['x'] + 0.8, 3), 'y' => round($rect['y'] + 1.4, 3), 'w' => round($rect['w'] - 1.6, 3), 'h' => round($rect['h'] - 2.8, 3),
            'children' => $children,
        ];
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $rect
     * @return array{x: float, y: float, w: float, h: float}
     */
    public static function conferenceTable(array $rect): array
    {
        return ['x' => $rect['x'] + $rect['w'] * 0.24, 'y' => $rect['y'] + $rect['h'] * 0.36, 'w' => $rect['w'] * 0.6, 'h' => $rect['h'] * 0.3];
    }
}
