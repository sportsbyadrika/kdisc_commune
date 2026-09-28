<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Services\Finance\FinanceReportService;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/**
 * Adapter: the Finance registers (invoice / receipt / credit note / deposit / outstanding) from
 * FinanceReportService::register() as report definitions, so they get the reports hub, sorting, XLSX and PDF.
 * Built by ReportRegistry (one instance per register type).
 */
final class FinanceRegisterReport extends Report
{
    public function __construct(private readonly FinanceReportService $finance, private readonly string $type)
    {
    }

    public function key(): string
    {
        return 'register-' . $this->type;
    }

    public function title(): string
    {
        return FinanceReportService::REGISTERS[$this->type][0];
    }

    public function description(): string
    {
        return match ($this->type) {
            'invoices' => 'Every GST invoice with recipient, place of supply, tax split and credits.',
            'receipts' => 'Receipts issued for verified payments, with mode and reference.',
            'credit-notes' => 'Credit notes with reason and GST reversal.',
            'deposits' => 'Security deposits due, received, adjusted, refunded and still held.',
            default => 'Bookings with money due now and the remaining balance.',
        };
    }

    public function group(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return FinanceReportService::REGISTERS[$this->type][1];
    }

    public function ability(): string
    {
        return $this->type === 'outstanding' ? 'dues.view' : 'reports.finance';
    }

    public function filters(): array
    {
        return $this->type === 'outstanding' ? [] : [Filter::range('fy')];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $reg = $this->finance->register($this->type, $f->from(), $f->to());
        $cols = array_map(static fn (array $c) => match ($c['type']) {
            'money' => Column::money($c['key'], $c['label'] . ' (₹)'),
            'date' => Column::date($c['key'], $c['label']),
            'mono' => Column::mono($c['key'], $c['label']),
            default => Column::text($c['key'], $c['label']),
        }, $reg['columns']);
        return new ReportResult($reg['title'], $cols, $reg['rows'], ucfirst(str_replace('-', ' ', $this->type)), subtitle: $reg['subtitle']);
    }

    public function fileStem(ReportFilters $filters): string
    {
        return $this->type === 'outstanding' ? 'outstanding-dues-' . $filters->today : parent::fileStem($filters);
    }
}
