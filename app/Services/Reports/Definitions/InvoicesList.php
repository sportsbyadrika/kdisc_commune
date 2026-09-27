<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Services\Finance\FinanceReportService;
use App\Services\Finance\FinancialYear;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Clock;

/**
 * Export of the "Invoices & receipts" hub tabs (invoices / receipts / credit notes) for a FY + search — rows from the
 * matching FinanceReportService register, filtered by the page's search text.
 */
final class InvoicesList extends Report
{
    public const TABS = ['invoices' => 'Invoices', 'receipts' => 'Receipts', 'credit-notes' => 'Credit notes'];
    private const SEARCH = [
        'invoices' => ['invoice_no', 'customer_name', 'booking_no', 'customer_gstin'],
        'receipts' => ['receipt_no', 'customer_name', 'booking_no', 'reference_no'],
        'credit-notes' => ['credit_note_no', 'customer_name', 'invoice_no'],
    ];

    public function __construct(private readonly FinanceReportService $finance, private readonly Clock $clock)
    {
    }

    public function key(): string
    {
        return 'invoices';
    }

    public function title(): string
    {
        return 'Invoices & receipts';
    }

    public function description(): string
    {
        return 'Invoices, receipts or credit notes of a financial year.';
    }

    public function group(): string
    {
        return 'lists';
    }

    public function icon(): string
    {
        return 'receipt-indian-rupee';
    }

    public function ability(): string
    {
        return 'invoices.view';
    }

    public function listed(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $fys = FinancialYear::recent($this->clock->today(), 4);
        return [
            Filter::select('tab', 'Documents', self::TABS, 'invoices'),
            Filter::select('fy', 'Financial year', array_combine($fys, array_map(static fn (string $fy) => 'FY ' . $fy, $fys)), $fys[0]),
            Filter::search('q'),
        ];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $tab = $f->get('tab', 'invoices');
        [$from, $to] = FinancialYear::range($f->get('fy'));
        $reg = $this->finance->register($tab, $from, $to);
        $q = mb_strtolower($f->get('q'));
        $rows = $q === '' ? $reg['rows'] : array_values(array_filter($reg['rows'], static function (array $r) use ($q, $tab): bool {
            foreach (self::SEARCH[$tab] as $k) {
                if (str_contains(mb_strtolower((string) ($r[$k] ?? '')), $q)) {
                    return true;
                }
            }
            return false;
        }));
        $cols = array_map(static fn (array $c) => match ($c['type']) {
            'money' => Column::money($c['key'], $c['label'] . ' (₹)'),
            'date' => Column::date($c['key'], $c['label']),
            'mono' => Column::mono($c['key'], $c['label']),
            default => Column::text($c['key'], $c['label']),
        }, $reg['columns']);
        return new ReportResult(self::TABS[$tab] . ' — FY ' . $f->get('fy'), $cols, $rows, self::TABS[$tab]);
    }

    public function fileStem(ReportFilters $filters): string
    {
        return $filters->get('tab', 'invoices') . '-FY-' . $filters->get('fy');
    }
}
