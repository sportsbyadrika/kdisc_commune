<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\App;
use GdImage;

/**
 * Static seat-map snapshot for PDFs (allotment letter, spec 9): draws a floor from a MiniMapPresenter config —
 * zones, every seat/cabin/room, the booking's own units highlighted with their codes — into a PNG with GD, and
 * returns it as a data URI. dompdf's SVG support is too limited for the explorer drawing, so this is raster.
 * Coordinates are the usual percentages of the floor image (x/y = top-left, w/h = size).
 */
final class SeatMapImage
{
    private const BRAND = [29, 78, 216];      // --color-brand-600
    private const BRAND_DARK = [11, 27, 63];  // --color-brand-900
    private const ACCENT = [225, 29, 116];    // --color-accent-500
    private const SEAT = [229, 231, 235];     // --color-line
    private const SEAT_EDGE = [156, 163, 175];

    /**
     * @param array<string, mixed> $map MiniMapPresenter::floor() (seats carry `mine`)
     */
    public static function dataUri(array $map, int $width = 1400): string
    {
        $fw = max(1, (int) ($map['floor']['width'] ?? 1600));
        $fh = max(1, (int) ($map['floor']['height'] ?? 1000));
        $height = (int) round($width * $fh / $fw);
        $pad = 16;
        $img = imagecreatetruecolor($width + 2 * $pad, $height + 2 * $pad);
        imageantialias($img, true);
        $white = self::color($img, [255, 255, 255]);
        imagefill($img, 0, 0, $white);
        $px = static fn (float $pct, int $size) => (int) round($pad + $pct / 100 * $size);
        $font = self::font(false);
        $bold = self::font(true);

        // floor outline
        imagefilledrectangle($img, $pad, $pad, $pad + $width, $pad + $height, self::color($img, [248, 249, 251]));
        imagesetthickness($img, 3);
        imagerectangle($img, $pad, $pad, $pad + $width, $pad + $height, self::color($img, [203, 213, 225]));

        // zones
        foreach ((array) ($map['zones'] ?? []) as $z) {
            $rgb = self::hex((string) ($z['colour'] ?? '#94a3b8'));
            $poly = [];
            foreach ((array) ($z['polygon'] ?? []) as [$x, $y]) {
                $poly[] = $px((float) $x, $width);
                $poly[] = $px((float) $y, $height);
            }
            if (count($poly) >= 6) {
                imagefilledpolygon($img, $poly, self::color($img, $rgb, 112));
                imagesetthickness($img, 2);
                imagepolygon($img, $poly, self::color($img, $rgb, 40));
            }
        }

        // seats: units first, chairs on top
        $seats = (array) ($map['seats'] ?? []);
        $mineParents = [];
        foreach ($seats as $s) {
            if (!empty($s['mine']) && $s['parent'] === null) {
                $mineParents[(int) $s['id']] = true;
            }
        }
        usort($seats, static fn (array $a, array $b) => ($a['parent'] === null ? 0 : 1) <=> ($b['parent'] === null ? 0 : 1));
        imagesetthickness($img, 2);
        $labels = [];
        foreach ($seats as $s) {
            $x1 = $px((float) $s['x'], $width);
            $y1 = $px((float) $s['y'], $height);
            $x2 = $px((float) $s['x'] + (float) $s['w'], $width);
            $y2 = $px((float) $s['y'] + (float) $s['h'], $height);
            $mine = !empty($s['mine']) || ($s['parent'] !== null && isset($mineParents[(int) $s['parent']]));
            $unit = $s['parent'] === null;
            if ($unit && in_array($s['kind'], ['cabin', 'room'], true)) {
                imagefilledrectangle($img, $x1, $y1, $x2, $y2, self::color($img, $mine ? [219, 232, 254] : [255, 255, 255]));
                imagerectangle($img, $x1, $y1, $x2, $y2, self::color($img, $mine ? self::BRAND : self::SEAT_EDGE));
            } else {
                imagefilledrectangle($img, $x1, $y1, $x2, $y2, self::color($img, $mine ? self::BRAND : self::SEAT));
                imagerectangle($img, $x1, $y1, $x2, $y2, self::color($img, $mine ? self::BRAND_DARK : self::SEAT_EDGE));
            }
            if ($mine && $unit) {
                $labels[] = [$s, $x1, $y1, $x2, $y2];
            }
        }
        // unit captions (cabins / rooms) and zone names, drawn on top with a white backing
        if ($bold !== null) {
            foreach ($seats as $s) {
                if ($s['parent'] === null && in_array($s['kind'], ['cabin', 'room'], true)) {
                    self::tag($img, $bold, 12, (string) ($s['label'] ?: $s['code']), $px((float) $s['x'], $width) + 8, $px((float) $s['y'], $height) + 22, [255, 255, 255], self::darken([100, 116, 139]));
                }
            }
            foreach ((array) ($map['zones'] ?? []) as $z) {
                if (!isset($z['rect']) || in_array($z['category'] ?? '', ['CABIN', 'CONF'], true)) {
                    continue;
                }
                $rgb = self::hex((string) ($z['colour'] ?? '#94a3b8'));
                self::tag($img, $bold, 13, strtoupper((string) $z['name']), $px((float) $z['rect']['x'], $width) + 8, $px((float) $z['rect']['y'], $height) + 24, [255, 255, 255], self::darken($rgb));
            }
        }

        // highlight rings + code labels for the allotted units (labels stacked upwards when they would collide)
        $placed = [];
        foreach ($labels as [$s, $x1, $y1, $x2, $y2]) {
            imagesetthickness($img, 4);
            imagerectangle($img, $x1 - 6, $y1 - 6, $x2 + 6, $y2 + 6, self::color($img, self::ACCENT));
            if ($bold === null) {
                continue;
            }
            $text = (string) $s['code'];
            $box = imagettfbbox(14, 0, $bold, $text);
            $tw = abs($box[2] - $box[0]) + 12;
            $tx = (int) max(4, min($width + 2 * $pad - $tw - 4, ($x1 + $x2) / 2 - $tw / 2));
            $ty = max($y1 - 14, 26);
            for ($guard = 0; $guard < 12; $guard++) {
                $hit = false;
                foreach ($placed as [$ax, $ay, $bx, $by]) {
                    if ($tx < $bx && $tx + $tw > $ax && $ty - 20 < $by && $ty + 6 > $ay) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    break;
                }
                $ty -= 28;
            }
            if ($ty < 22) {
                $ty = $y2 + 30;
            }
            imageline($img, (int) (($x1 + $x2) / 2), $y1 - 6, (int) (($x1 + $x2) / 2), $ty + 6, self::color($img, self::ACCENT));
            imagefilledrectangle($img, $tx, $ty - 20, $tx + $tw, $ty + 6, self::color($img, self::ACCENT));
            imagettftext($img, 14, 0, $tx + 6, $ty, $white, $bold, $text);
            $placed[] = [$tx, $ty - 20, $tx + $tw, $ty + 6];
        }
        ob_start();
        imagepng($img, null, 6);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /**
     * Small caption with a solid backing box.
     *
     * @param array{0: int, 1: int, 2: int} $bg
     * @param array{0: int, 1: int, 2: int} $fg
     */
    private static function tag(GdImage $img, string $font, int $size, string $text, int $x, int $y, array $bg, array $fg): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $w = abs($box[2] - $box[0]);
        imagefilledrectangle($img, $x - 4, $y - $size - 5, $x + $w + 4, $y + 5, self::color($img, $bg, 20));
        imagettftext($img, $size, 0, $x, $y, self::color($img, $fg), $font, $text);
    }

    private static function font(bool $bold): ?string
    {
        $file = App::basePath('vendor/dompdf/dompdf/lib/fonts/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));
        return is_file($file) && function_exists('imagettftext') ? $file : null;
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private static function color(GdImage $img, array $rgb, int $alpha = 0): int
    {
        return (int) imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function hex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [148, 163, 184];
        }
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     * @return array{0: int, 1: int, 2: int}
     */
    private static function darken(array $rgb): array
    {
        return [(int) ($rgb[0] * 0.55), (int) ($rgb[1] * 0.55), (int) ($rgb[2] * 0.55)];
    }
}
