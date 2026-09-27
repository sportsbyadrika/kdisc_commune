<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Core\Response;
use App\Services\Finance\FinanceSettings;
use App\Services\Pdf\PdfService;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Clock;

/**
 * XLSX / PDF downloads of a report run (same definition + filters as the page). PDFs render pdf/report through
 * PdfService (dompdf, A4 landscape by default) and are capped at PDF_MAX_ROWS rows per table — the XLSX always
 * carries everything.
 */
final class ReportExporter
{
    public const PDF_MAX_ROWS = 1500;
    public const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly XlsxExporter $xlsx,
        private readonly PdfService $pdf,
        private readonly FinanceSettings $settings,
        private readonly Clock $clock,
    ) {
    }

    public function generatedAt(): string
    {
        return $this->clock->now()->format('d M Y, g:i a');
    }

    public function xlsxBytes(ReportResult $result, ReportFilters $filters, string $by): string
    {
        return $this->xlsx->bytes($this->xlsx->workbook($result, $filters->describe(), $this->generatedAt(), $by));
    }

    public function pdfBytes(Report $report, ReportResult $result, ReportFilters $filters, string $by): string
    {
        return $this->pdf->render('pdf/report', [
            'result' => $result,
            'filterLines' => $filters->describe(),
            'generatedAt' => $this->generatedAt(),
            'by' => $by,
            'supplier' => $this->settings->supplier(),
            'maxRows' => self::PDF_MAX_ROWS,
        ], 'A4', $report->orientation());
    }

    public function xlsxResponse(Report $report, ReportResult $result, ReportFilters $filters, string $by): Response
    {
        return self::attachment($this->xlsxBytes($result, $filters, $by), $report->fileStem($filters) . '.xlsx', self::XLSX_MIME);
    }

    public function pdfResponse(Report $report, ReportResult $result, ReportFilters $filters, string $by): Response
    {
        $bytes = $this->pdfBytes($report, $result, $filters, $by);
        $name = str_replace(['"', "\r", "\n"], '', $report->fileStem($filters) . '.pdf');
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $name),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'",
        ]);
    }

    public static function attachment(string $bytes, string $filename, string $mime): Response
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        return new Response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $safe),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
