<?php

declare(strict_types=1);

namespace App\Services\Space;

/**
 * Geometry helpers for seat maps. All coordinates are PERCENTAGES of the floor
 * image: x/y = top-left corner, w/h = size (so they map directly to CSS
 * left/top/width/height or SVG x/y/width/height on a 0-100 viewBox).
 *
 * Used by the seeders and intended for the Layout Designer ("add row of N").
 */
final class GridLayout
{
    /** Image aspect ratio (width / height) of the placeholder floor plans (1600 x 1000). */
    public const ASPECT = 1.6;

    /**
     * Evenly distribute rows x cols seats inside a rectangle.
     *
     * @param array{x: float, y: float, w: float, h: float} $rect
     * @return list<array{x: float, y: float, w: float, h: float, row: int, col: int}>
     */
    public static function grid(array $rect, int $rows, int $cols, float $seatW = 3.4, ?float $seatH = null, float $padX = 1.5, float $padY = 3.0): array
    {
        $seatH ??= round($seatW * self::ASPECT, 3);
        $innerW = $rect['w'] - 2 * $padX;
        $innerH = $rect['h'] - 2 * $padY;
        $cellW = $innerW / max(1, $cols);
        $cellH = $innerH / max(1, $rows);
        $seats = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $seats[] = [
                    'x' => round($rect['x'] + $padX + $c * $cellW + ($cellW - $seatW) / 2, 3),
                    'y' => round($rect['y'] + $padY + $r * $cellH + ($cellH - $seatH) / 2, 3),
                    'w' => round($seatW, 3),
                    'h' => round($seatH, 3),
                    'row' => $r,
                    'col' => $c,
                ];
            }
        }
        return $seats;
    }

    /**
     * A single row of N seats starting at (x, y) with a fixed gap — the Designer's "add row of N".
     *
     * @return list<array{x: float, y: float, w: float, h: float}>
     */
    public static function row(float $x, float $y, int $count, float $seatW = 3.4, ?float $seatH = null, float $gap = 1.2): array
    {
        $seatH ??= round($seatW * self::ASPECT, 3);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['x' => round($x + $i * ($seatW + $gap), 3), 'y' => round($y, 3), 'w' => $seatW, 'h' => $seatH];
        }
        return $out;
    }

    /**
     * Bounding box of a list of rects, grown by a margin.
     *
     * @param list<array{x: float, y: float, w: float, h: float}> $rects
     * @return array{x: float, y: float, w: float, h: float}
     */
    public static function bounds(array $rects, float $margin = 0.0): array
    {
        $minX = min(array_column($rects, 'x'));
        $minY = min(array_column($rects, 'y'));
        $maxX = max(array_map(static fn ($r) => $r['x'] + $r['w'], $rects));
        $maxY = max(array_map(static fn ($r) => $r['y'] + $r['h'], $rects));
        return [
            'x' => round($minX - $margin, 3),
            'y' => round($minY - $margin * self::ASPECT, 3),
            'w' => round($maxX - $minX + 2 * $margin, 3),
            'h' => round($maxY - $minY + 2 * $margin * self::ASPECT, 3),
        ];
    }

    /**
     * Rectangle -> polygon [[x,y],...] (clockwise from top-left).
     * @param array{x: float, y: float, w: float, h: float} $r
     * @return list<array{0: float, 1: float}>
     */
    public static function rectToPolygon(array $r): array
    {
        return [[$r['x'], $r['y']], [$r['x'] + $r['w'], $r['y']], [$r['x'] + $r['w'], $r['y'] + $r['h']], [$r['x'], $r['y'] + $r['h']]];
    }
}
