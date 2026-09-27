<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR codes as self-contained SVG data URIs (CSP allows img-src data:). Used on the visitor ID card —
 * the code holds the Unique Visitor ID so the front desk can scan it into the visitor search.
 *
 *   <img src="<?= e($qr) ?>" alt="QR code">   // $qr = $renderer->dataUri('CMN-KTR-I-2026-00001')
 */
final class QrCodeRenderer
{
    public function dataUri(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => null,
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'svgAddXmlHeader' => false,
            'connectPaths' => true,
        ]);
        return (string) (new QRCode($options))->render($data);
    }

    /**
     * PNG data URI (GD) — for PDFs: dompdf renders raster images more reliably than SVG.
     */
    public function pngDataUri(string $data, int $scale = 6): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);
        return (string) (new QRCode($options))->render($data);
    }
}
