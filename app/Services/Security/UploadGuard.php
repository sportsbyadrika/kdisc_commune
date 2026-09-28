<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * Content checks for uploads that run BEFORE a file is decoded (all return an error message or null):
 *
 *  - image(): decompression bombs — reads only the header (getimagesize) and refuses huge pixel counts, so a
 *    5 MB PNG that would inflate to gigabytes in GD never reaches Intervention/GD.
 *  - pdf(): refuses PDFs with active content (/JavaScript, /JS, /Launch, /EmbeddedFile, /RichMedia). KYC scans and
 *    payment proofs never need them. (Objects inside compressed object streams are not inspected — the files are
 *    only ever served back with a sandboxing CSP and Content-Disposition, never executed server-side.)
 *  - zip(): XLSX zip bombs — entry count, total uncompressed size and compression ratio from the central
 *    directory, without extracting anything.
 */
final class UploadGuard
{
    public const MAX_IMAGE_PIXELS = 40_000_000;   // 40 MP (e.g. 8000 × 5000) — phones shoot ≤ 50 MP but scale fine below

    public const MAX_IMAGE_EDGE = 12_000;

    public static function image(string $path, int $maxPixels = self::MAX_IMAGE_PIXELS, int $maxEdge = self::MAX_IMAGE_EDGE): ?string
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return 'The image could not be read. Please upload a clear JPG, PNG or WebP photo.';
        }
        if ($info[0] > $maxEdge || $info[1] > $maxEdge || $info[0] * $info[1] > $maxPixels) {
            return sprintf('The image is too large (%d × %d pixels). Please upload a photo under %d megapixels.', $info[0], $info[1], intdiv($maxPixels, 1_000_000));
        }
        return null;
    }

    public static function pdf(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return 'The PDF could not be read.';
        }
        $head = (string) fread($fh, 1024);
        if (!str_starts_with(ltrim($head, "\x00\t\n\r "), '%PDF-')) {
            fclose($fh);
            return 'The PDF appears to be damaged.';
        }
        rewind($fh);
        $tail = '';
        $bad = null;
        while (!feof($fh)) {
            $chunk = $tail . (string) fread($fh, 1 << 20);
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b#', $chunk, $m) === 1) {
                $bad = $m[1];
                break;
            }
            $tail = substr($chunk, -32);   // keys split across chunk boundaries
        }
        fclose($fh);
        return $bad !== null ? 'PDFs with embedded scripts or attachments are not accepted. Please upload a plain scan or print to PDF again.' : null;
    }

    public static function zip(string $path, int $maxUncompressed = 64 * 1024 * 1024, int $maxEntries = 2000, int $maxRatio = 250): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return 'That is not a valid .xlsx workbook.';
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
                return 'The workbook has an unexpected structure.';
            }
            $total = 0;
            $compressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    return 'That is not a valid .xlsx workbook.';
                }
                $total += (int) $stat['size'];
                $compressed += (int) $stat['comp_size'];
                if ($total > $maxUncompressed) {
                    return 'The workbook expands to more than ' . intdiv($maxUncompressed, 1048576) . ' MB — split it into smaller files.';
                }
            }
            if ($compressed > 0 && $total / $compressed > $maxRatio && $total > 8 * 1024 * 1024) {
                return 'The workbook is compressed suspiciously well — it was rejected as a possible zip bomb.';
            }
            return null;
        } finally {
            $zip->close();
        }
    }
}
