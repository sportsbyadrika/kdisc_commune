<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FinancialYear;
use App\Services\Pdf\PdfService;
use App\Support\Clock;

/**
 * Finance registers (invoice / receipt / credit note / deposit / outstanding dues) as HTML tables with a PDF export
 * (A4 landscape). Filter by financial year or a custom date range. Data: FinanceReportService::register().
 */
final class RegisterController extends Controller
{
    public function __construct(private readonly FinanceReportService $reports, private readonly PdfService $pdf, private readonly FinanceSettings $settings, private readonly Clock $clock)
    {
    }

    public function index(Request $request): Response
    {
        [$type, $filters, $from, $to] = $this->filters($request);
        return $this->view('staff/finance/registers', [
            'title' => 'Finance registers',
            'type' => $type,
            'filters' => $filters,
            'fys' => FinancialYear::recent($this->clock->today(), 4),
            'register' => $this->reports->register($type, $from, $to),
        ]);
    }

    public function pdf(Request $request, string $type): Response
    {
        if (!isset(FinanceReportService::REGISTERS[$type])) {
            throw new NotFoundException();
        }
        [, , $from, $to] = $this->filters($request, $type);
        $register = $this->reports->register($type, $from, $to);
        $bytes = $this->pdf->render('pdf/register', ['register' => $register, 'generatedAt' => $this->clock->now()->format('d M Y, g:i a'), 'by' => (string) (staff()['name'] ?? ''), 'supplier' => $this->settings->supplier()], 'A4', 'landscape');
        return FinanceDocumentController::inline($bytes, sprintf('%s-%s-to-%s.pdf', $type, $from, $to));
    }

    /** @return array{0: string, 1: array<string, string>, 2: string, 3: string} */
    private function filters(Request $request, ?string $type = null): array
    {
        $type ??= array_key_exists($request->string('type'), FinanceReportService::REGISTERS) ? $request->string('type') : 'invoices';
        $fy = $request->string('fy');
        $fy = FinancialYear::valid($fy) ? $fy : FinancialYear::of($this->clock->today());
        [$from, $to] = FinancialYear::range($fy);
        $f = $request->string('from');
        $t = $request->string('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && $f <= $t) {
            [$from, $to] = [$f, $t];
        } else {
            $f = $t = '';
        }
        return [$type, ['fy' => $fy, 'from' => $f, 'to' => $t], $from, $to];
    }
}
