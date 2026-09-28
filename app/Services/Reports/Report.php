<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * A report DEFINITION: metadata + filters + run(). One definition feeds the HTML table (with sort + pagination),
 * the PDF (pdf/report via PdfService) and the XLSX export (Export\XlsxExporter), so all three stay consistent.
 *
 * Add a report: extend this class in Reports\Definitions, list it in ReportRegistry::DEFINITIONS, pick an ability
 * (StaffRole::abilities()). Queries belong in the definition (or a shared service such as OccupancyService /
 * FinanceReportService) — never in the view.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Hub section: operations | finance | visitors | lists */
    abstract public function group(): string;

    abstract public function icon(): string;

    /** @return list<Filter> */
    abstract public function filters(): array;

    abstract public function run(ReportFilters $filters): ReportResult;

    /** Ability needed to open / export it. */
    public function ability(): string
    {
        return 'reports.view';
    }

    /** PDF paper orientation. */
    public function orientation(): string
    {
        return 'landscape';
    }

    /** Shown in the hub (list exports are reached from their list pages). */
    public function listed(): bool
    {
        return true;
    }

    /** Download file name stem. */
    public function fileStem(ReportFilters $filters): string
    {
        return $this->key() . '-' . $filters->from() . ($filters->to() !== $filters->from() ? '-to-' . $filters->to() : '');
    }
}
