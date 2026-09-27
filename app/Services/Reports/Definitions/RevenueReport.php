<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Services\Finance\FinanceReportService;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use DateTimeImmutable;

/**
 * Invoiced revenue (taxable value, net of credit notes) by space type, add-on facility or month. Space type and
 * facility reuse FinanceReportService::revenueByCategory/Facility (the Finance dashboard figures).
 */
final class RevenueReport extends Report
{
    public const GROUPS = ['month' => 'Month', 'category' => 'Space type', 'facility' => 'Add-on facility'];

    public function __construct(private readonly Database $db, private readonly FinanceReportService $finance)
    {
    }

    public function key(): string
    {
        return 'revenue';
    }

    public function title(): string
    {
        return 'Revenue';
    }

    public function description(): string
    {
        return 'Invoiced taxable value net of credit notes, by month, space type or add-on facility.';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return 'trending-up';
    }

    public function filters(): array
    {
        return [Filter::range('fy'), Filter::select('group', 'Group by', self::GROUPS, 'month')];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $by = $f->get('group', 'month');
        $notes = ['Taxable value of GST invoices dated in the period, less credit notes against them. Security deposits are never invoiced.'];
        if ($by !== 'month') {
            $rows = $by === 'category' ? $this->finance->revenueByCategory($f->from(), $f->to()) : $this->finance->revenueByFacility($f->from(), $f->to());
            $total = array_sum(array_column($rows, 'amount'));
            $rows = array_map(static fn (array $r) => $r + ['share' => $total > 0 ? round($r['amount'] / $total, 4) : 0.0], $rows);
            return new ReportResult('Revenue by ' . strtolower(self::GROUPS[$by]), [
                Column::text('label', self::GROUPS[$by]), Column::money('amount', 'Net taxable (₹)'), Column::pct('share', 'Share'),
            ], $rows, 'Revenue', ['share' => $rows !== [] ? 1.0 : 0.0], notes: $notes,
                chart: ['kind' => 'hbar', 'label' => 'Net taxable', 'labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'amount')]);
        }
        $inv = [];
        foreach ($this->db->select(
            "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS m, COUNT(*) AS n, SUM(taxable_value) AS t, SUM(cgst + sgst + igst) AS g, SUM(total) AS tot
             FROM invoices WHERE invoice_date BETWEEN ? AND ? GROUP BY m",
            [$f->from(), $f->to()],
        ) as $r) {
            $inv[(string) $r['m']] = $r;
        }
        $cn = [];
        foreach ($this->db->select(
            "SELECT DATE_FORMAT(note_date, '%Y-%m') AS m, SUM(taxable_value) AS t, SUM(cgst + sgst + igst) AS g, SUM(total) AS tot
             FROM credit_notes WHERE note_date BETWEEN ? AND ? GROUP BY m",
            [$f->from(), $f->to()],
        ) as $r) {
            $cn[(string) $r['m']] = $r;
        }
        $rows = [];
        for ($m = new DateTimeImmutable(substr($f->from(), 0, 7) . '-01'); $m->format('Y-m') <= substr($f->to(), 0, 7); $m = $m->modify('+1 month')) {
            $k = $m->format('Y-m');
            $i = $inv[$k] ?? ['n' => 0, 't' => 0, 'g' => 0, 'tot' => 0];
            $c = $cn[$k] ?? ['t' => 0, 'g' => 0, 'tot' => 0];
            $rows[] = [
                'label' => $m->format('M Y'), 'invoices' => (int) $i['n'], 'taxable' => round((float) $i['t'], 2), 'credited' => round((float) $c['t'], 2),
                'net' => round((float) $i['t'] - (float) $c['t'], 2), 'gst' => round((float) $i['g'] - (float) $c['g'], 2),
                'total' => round((float) $i['tot'] - (float) $c['tot'], 2),
            ];
        }
        return new ReportResult('Revenue by month', [
            Column::text('label', 'Month', 12), Column::int('invoices', 'Invoices'), Column::money('taxable', 'Invoiced taxable (₹)'), Column::money('credited', 'Credit notes (₹)'),
            Column::money('net', 'Net taxable (₹)'), Column::money('gst', 'Net GST (₹)'), Column::money('total', 'Net total (₹)'),
        ], $rows, 'Revenue', notes: $notes,
            chart: ['kind' => 'bar-money', 'label' => 'Net taxable', 'labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'net')]);
    }
}
