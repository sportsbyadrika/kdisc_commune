<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\App;
use App\Core\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * dompdf wrapper for every PDF (spec 9): finance documents, allotment letters, ID cards and registers.
 *
 *   $bytes = $pdf->render('pdf/invoice', $data);                    // A4 portrait
 *   $bytes = $pdf->render('pdf/register', $data, 'A4', 'landscape');
 *   $bytes = $pdf->render('pdf/id-card', $data, [0, 0, 243.0, 153.0]); // CR80 card in points
 *
 * Templates live in resources/views/pdf/ and use tables + the print stylesheet (pdf/print-css) — dompdf has no
 * flex/grid and Tailwind is never loaded. Security: remote resources are disabled (isRemoteEnabled=false), the
 * file chroot is the project directory, PHP in templates is not evaluated by dompdf. Images (QR, logo,
 * signature, photos, seat maps) are embedded as data URIs (imageDataUri()).
 *
 * Fonts: DejaVu Sans (bundled with dompdf) is the default — it has the ₹ sign. dompdf keeps font metrics in
 * storage/cache/dompdf. Malayalam needs a Noto Sans Malayalam TTF registered via @font-face (see README).
 *
 * Stored documents: storage/pdf/{type}/{fy}/{slug}.pdf (store()/path()).
 */
final class PdfService
{
    public function __construct(private readonly View $view)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param string|array{0: float, 1: float, 2: float, 3: float} $paper
     */
    public function render(string $template, array $data, string|array $paper = 'A4', string $orientation = 'portrait'): string
    {
        $html = $this->view->render($template, $data);
        return $this->fromHtml($html, $paper, $orientation, (bool) ($data['pageNumbers'] ?? true));
    }

    /** @param string|array{0: float, 1: float, 2: float, 3: float} $paper */
    public function fromHtml(string $html, string|array $paper = 'A4', string $orientation = 'portrait', bool $pageNumbers = true): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();
        if ($pageNumbers && is_string($paper)) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $w = $canvas->get_width();
            $h = $canvas->get_height();
            $canvas->page_text($w - 90, $h - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.42, 0.45, 0.5]);
        }
        return (string) $dompdf->output();
    }

    public function options(): Options
    {
        $cache = App::basePath('storage/cache/dompdf');
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([App::basePath()]);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setDpi(96);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultMediaType('print');
        return $options;
    }

    // ------------------------------------------------------------------ storage

    public static function root(): string
    {
        return App::basePath('storage/pdf');
    }

    /** Write a document once; returns the path relative to storage/pdf. */
    public function store(string $relative, string $bytes): string
    {
        $relative = ltrim(str_replace('..', '', $relative), '/');
        $file = self::root() . '/' . $relative;
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create PDF directory.');
        }
        if (file_put_contents($file, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write PDF file.');
        }
        @chmod($file, 0640);
        return $relative;
    }

    /** Absolute path of a stored PDF, or null when missing / outside storage/pdf. */
    public function path(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }
        $root = realpath(self::root());
        $file = realpath(self::root() . '/' . ltrim($relative, '/'));
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        return $file;
    }

    // ------------------------------------------------------------------ images

    /**
     * Any local raster image (JPG/PNG/WebP/GIF) → PNG/JPEG data URI, scaled down to $maxEdge px (GD). Returns null
     * when the file is missing or unreadable, so templates simply leave the image out.
     */
    public static function imageDataUri(?string $path, int $maxEdge = 600, bool $jpeg = false): ?string
    {
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $maxEdge / max($w, $h));
        if ($scale < 1.0) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $img = $dst;
        }
        ob_start();
        if ($jpeg) {
            imagejpeg($img, null, 88);
        } else {
            imagesavealpha($img, true);
            imagepng($img, null, 6);
        }
        $bytes = (string) ob_get_clean();
        return 'data:image/' . ($jpeg ? 'jpeg' : 'png') . ';base64,' . base64_encode($bytes);
    }

    /** The shared print stylesheet (resources/views/pdf/print.css), inlined into each template's <style>. */
    public static function css(): string
    {
        return (string) file_get_contents(App::basePath('resources/views/pdf/print.css'));
    }
}
