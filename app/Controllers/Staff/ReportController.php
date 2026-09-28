<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\StaffRole;
use App\Services\AuditLog;
use App\Services\Reports\Export\ReportExporter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use App\Support\Clock;

/**
 * Reports hub (/staff/reports) and every report page + its XLSX / PDF export. The definition (Reports\Definitions)
 * decides filters, columns and rows; this controller only resolves filters from the query string, checks the
 * report's ability, sorts / paginates the HTML table and streams the exports (audited as report.export).
 */
final class ReportController extends StaffController
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportExporter $exporter,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(): Response
    {
        $role = StaffRole::from((string) $this->user()['role']);
        return $this->view('staff/reports/index', [
            'title' => 'Reports',
            'subtitle' => 'Every report opens with filters and exports to XLSX and PDF with the same figures.',
            'hub' => $this->registry->hub($role),
        ]);
    }

    public function show(Request $request, string $key): Response
    {
        $report = $this->report($key);
        $filters = ReportFilters::make($report, $request->all(), $this->clock->today());
        $result = $report->run($filters);
        $sheets = $result->allSheets();
        $sheetNo = max(0, min(count($sheets) - 1, $request->int('sheet')));
        $sheet = $sheets[$sheetNo];
        $sort = $request->string('sort');
        $dir = $request->string('dir') === 'desc' ? 'desc' : 'asc';
        $rows = $sheet->column($sort) !== null ? $sheet->sorted($sort, $dir) : $sheet->rows;
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->int('page', 1)));
        return $this->view('staff/reports/show', [
            'title' => $report->title(),
            'report' => $report,
            'filters' => $filters,
            'result' => $result,
            'sheet' => $sheet,
            'sheetNo' => $sheetNo,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'pager' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => self::PER_PAGE],
            'sort' => $sheet->column($sort) !== null ? $sort : '',
            'dir' => $dir,
            'backUrl' => $this->backUrl($report, $request),
        ]);
    }

    public function xlsx(Request $request, string $key): Response
    {
        $report = $this->report($key);
        $filters = ReportFilters::make($report, $request->all(), $this->clock->today());
        $result = $report->run($filters);
        $this->audited($report, $filters, 'xlsx', count($result->rows));
        return $this->exporter->xlsxResponse($report, $result, $filters, (string) ($this->user()['name'] ?? ''));
    }

    public function pdf(Request $request, string $key): Response
    {
        $report = $this->report($key);
        $filters = ReportFilters::make($report, $request->all(), $this->clock->today());
        $result = $report->run($filters);
        $this->audited($report, $filters, 'pdf', count($result->rows));
        return $this->exporter->pdfResponse($report, $result, $filters, (string) ($this->user()['name'] ?? ''));
    }

    private function report(string $key): Report
    {
        $report = $this->registry->find($key) ?? throw new NotFoundException('Report not found.');
        if (!$this->can($report->ability())) {
            throw new ForbiddenException();
        }
        return $report;
    }

    private function audited(Report $report, ReportFilters $filters, string $format, int $rows): void
    {
        $this->audit->record('report.export', 'report', null, null, ['report' => $report->key(), 'format' => $format, 'rows' => $rows, 'filters' => $filters->query()]);
    }

    /** List exports link back to their page. */
    private function backUrl(Report $report, Request $request): ?string
    {
        $q = $request->all();
        return match ($report->key()) {
            'visitors' => url('staff.visitors.index', array_intersect_key($q, array_flip(['q', 'type', 'kyc']))),
            'bookings-list' => url('staff.bookings.index', array_intersect_key($q, array_flip(['tab', 'q', 'category', 'floor', 'source', 'within', 'from', 'to']))),
            'payments' => url('staff.payments.index', array_intersect_key($q, array_flip(['status', 'mode', 'kind', 'q', 'from', 'to', 'sort']))),
            'invoices' => url('staff.invoices.index', array_intersect_key($q, array_flip(['tab', 'fy', 'q']))),
            default => null,
        };
    }
}
