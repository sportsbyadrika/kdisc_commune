<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\FinancialYear;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\Clock;
use App\Support\IndianStates;
use DateTimeImmutable;

/**
 * GSTR-1-friendly GST summary for a month or a financial year (spec 6.4). Sheets follow the GSTR-1 offline tool
 * sections so Finance can copy them across:
 *
 *   Summary      section totals; "Net" equals invoices minus credit notes (= FinanceReportService::gst())
 *   B2B          registered recipients (GSTIN): one row per invoice per tax rate
 *   B2CL         unregistered, inter-state, invoice value above the B2CL limit (₹1,00,000)
 *   B2CS         other unregistered supplies, by place of supply + rate, NET of their credit notes (GSTR-1 practice)
 *   Credit notes every credit note of the period with its section: CDNR (registered), CDNUR (B2CL), or netted in B2CS
 *   HSN-SAC      by SAC + rate, invoices less credit notes
 */
final class GstSummaryReport extends Report
{
    public const B2CL_LIMIT = 100000.0;

    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly FinanceReportService $finance)
    {
    }

    public function key(): string
    {
        return 'gst-summary';
    }

    public function title(): string
    {
        return 'GST summary (GSTR-1)';
    }

    public function description(): string
    {
        return 'B2B, B2CL, B2CS, credit notes and HSN/SAC summary for a month or financial year — GSTR-1 ready XLSX.';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return 'landmark';
    }

    public function ability(): string
    {
        return 'reports.finance';
    }

    /** @return array<string, string> period value => label: months of the current and last FY, then the FYs */
    public function periods(): array
    {
        $today = $this->clock->today();
        $out = [];
        foreach (FinancialYear::recent($today, 2) as $fy) {
            [$start, $end] = FinancialYear::range($fy);
            for ($m = new DateTimeImmutable($end); $m->format('Y-m') >= substr($start, 0, 7); $m = $m->modify('first day of -1 month')) {
                if ($m->format('Y-m') <= substr($today, 0, 7)) {
                    $out[$m->format('Y-m')] = $m->format('F Y');
                }
            }
        }
        foreach (FinancialYear::recent($today, 3) as $fy) {
            $out['fy:' . $fy] = 'Whole FY ' . $fy;
        }
        return $out;
    }

    public function filters(): array
    {
        return [Filter::select('period', 'Return period', $this->periods(), substr($this->clock->today(), 0, 7))];
    }

    /** @return array{0: string, 1: string} */
    public static function range(string $period): array
    {
        if (str_starts_with($period, 'fy:')) {
            return FinancialYear::range(substr($period, 3));
        }
        $m = new DateTimeImmutable($period . '-01');
        return [$m->format('Y-m-01'), $m->format('Y-m-t')];
    }

    public function fileStem(ReportFilters $filters): string
    {
        return 'gstr1-summary-' . str_replace('fy:', 'FY-', $filters->get('period'));
    }

    public function run(ReportFilters $f): ReportResult
    {
        [$from, $to] = self::range($f->get('period'));
        $pos = static fn (?string $code) => $code !== null && $code !== '' ? sprintf('%s-%s', $code, IndianStates::name($code)) : '';

        // ---- invoices by rate
        $lines = $this->db->select(
            'SELECT i.id, i.invoice_no, i.invoice_date, i.customer_name, i.customer_gstin, i.place_of_supply, i.total, i.status,
                    ii.gst_rate, SUM(ii.taxable_value) AS taxable, SUM(ii.igst) AS igst, SUM(ii.cgst) AS cgst, SUM(ii.sgst) AS sgst
             FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.id
             WHERE i.invoice_date BETWEEN ? AND ? GROUP BY i.id, ii.gst_rate ORDER BY i.fy, i.seq, ii.gst_rate',
            [$from, $to],
        );
        $inter = static fn (array $r) => (string) $r['place_of_supply'] !== IndianStates::HOME;
        $section = static fn (array $r) => (string) ($r['customer_gstin'] ?? '') !== '' ? 'B2B' : ($inter($r) && (float) $r['total'] > self::B2CL_LIMIT ? 'B2CL' : 'B2CS');
        $b2b = $b2cl = $b2csMap = [];
        $docs = ['B2B' => [], 'B2CL' => [], 'B2CS' => []];
        foreach ($lines as $r) {
            $sec = $section($r);
            $docs[$sec][(int) $r['id']] = true;
            $base = [
                'rate' => (float) $r['gst_rate'], 'taxable' => round((float) $r['taxable'], 2), 'igst' => round((float) $r['igst'], 2),
                'cgst' => round((float) $r['cgst'], 2), 'sgst' => round((float) $r['sgst'], 2), 'cess' => 0.0,
            ];
            if ($sec === 'B2B') {
                $b2b[] = ['gstin' => $r['customer_gstin'], 'name' => $r['customer_name'], 'invoice_no' => $r['invoice_no'], 'invoice_date' => $r['invoice_date'],
                    'value' => round((float) $r['total'], 2), 'pos' => $pos((string) $r['place_of_supply']), 'reverse' => 'N', 'type' => 'Regular B2B'] + $base;
            } elseif ($sec === 'B2CL') {
                $b2cl[] = ['invoice_no' => $r['invoice_no'], 'invoice_date' => $r['invoice_date'], 'name' => $r['customer_name'], 'value' => round((float) $r['total'], 2),
                    'pos' => $pos((string) $r['place_of_supply'])] + $base;
            } else {
                $k = $r['place_of_supply'] . '|' . $r['gst_rate'];
                $b2csMap[$k] ??= ['type' => 'OE', 'pos' => $pos((string) $r['place_of_supply']), 'rate' => (float) $r['gst_rate'], 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0, 'invoices' => 0, 'notes' => 0];
                foreach (['taxable', 'igst', 'cgst', 'sgst'] as $c) {
                    $b2csMap[$k][$c] = round($b2csMap[$k][$c] + $base[$c], 2);
                }
                $b2csMap[$k]['invoices']++;
            }
        }

        // ---- credit notes by rate
        $noteRows = [];
        $noteDocs = ['CDNR' => [], 'CDNUR' => [], 'B2CS' => []];
        foreach ($this->db->select(
            'SELECT cn.id, cn.credit_note_no, cn.note_date, cn.reason, cn.total, i.invoice_no, i.invoice_date, i.customer_name, i.customer_gstin, i.place_of_supply, i.total AS invoice_total,
                    cni.gst_rate, SUM(cni.taxable_value) AS taxable, SUM(cni.igst) AS igst, SUM(cni.cgst) AS cgst, SUM(cni.sgst) AS sgst
             FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id JOIN credit_note_items cni ON cni.credit_note_id = cn.id
             WHERE cn.note_date BETWEEN ? AND ? GROUP BY cn.id, cni.gst_rate ORDER BY cn.fy, cn.seq, cni.gst_rate',
            [$from, $to],
        ) as $r) {
            $inv = ['customer_gstin' => $r['customer_gstin'], 'place_of_supply' => $r['place_of_supply'], 'total' => $r['invoice_total']];
            $sec = match ($section($inv)) {
                'B2B' => 'CDNR',
                'B2CL' => 'CDNUR',
                default => 'B2CS',
            };
            $noteDocs[$sec][(int) $r['id']] = true;
            $row = [
                'section' => $sec === 'B2CS' ? 'B2CS (netted)' : $sec, 'gstin' => $r['customer_gstin'] ?? '', 'name' => $r['customer_name'], 'note_no' => $r['credit_note_no'],
                'note_date' => $r['note_date'], 'note_type' => 'C', 'pos' => $pos((string) $r['place_of_supply']), 'value' => round((float) $r['total'], 2),
                'invoice_no' => $r['invoice_no'], 'invoice_date' => $r['invoice_date'], 'rate' => (float) $r['gst_rate'], 'taxable' => round((float) $r['taxable'], 2),
                'igst' => round((float) $r['igst'], 2), 'cgst' => round((float) $r['cgst'], 2), 'sgst' => round((float) $r['sgst'], 2), 'cess' => 0.0,
            ];
            $noteRows[] = $row;
            if ($sec === 'B2CS') {
                $k = $r['place_of_supply'] . '|' . $r['gst_rate'];
                $b2csMap[$k] ??= ['type' => 'OE', 'pos' => $pos((string) $r['place_of_supply']), 'rate' => (float) $r['gst_rate'], 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0, 'invoices' => 0, 'notes' => 0];
                foreach (['taxable', 'igst', 'cgst', 'sgst'] as $c) {
                    $b2csMap[$k][$c] = round($b2csMap[$k][$c] - $row[$c], 2);
                }
                $b2csMap[$k]['notes']++;
            }
        }
        ksort($b2csMap);
        $b2cs = array_values($b2csMap);

        // ---- HSN / SAC
        $hsn = [];
        foreach ([
            ['SELECT ii.sac, ii.gst_rate, COUNT(*) AS n, SUM(ii.total) AS value, SUM(ii.taxable_value) AS taxable, SUM(ii.igst) AS igst, SUM(ii.cgst) AS cgst, SUM(ii.sgst) AS sgst
              FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.invoice_date BETWEEN ? AND ? GROUP BY ii.sac, ii.gst_rate', 1],
            ['SELECT cni.sac, cni.gst_rate, 0 AS n, SUM(cni.total) AS value, SUM(cni.taxable_value) AS taxable, SUM(cni.igst) AS igst, SUM(cni.cgst) AS cgst, SUM(cni.sgst) AS sgst
              FROM credit_note_items cni JOIN credit_notes cn ON cn.id = cni.credit_note_id WHERE cn.note_date BETWEEN ? AND ? GROUP BY cni.sac, cni.gst_rate', -1],
        ] as [$sql, $sign]) {
            foreach ($this->db->select($sql, [$from, $to]) as $r) {
                $k = $r['sac'] . '|' . $r['gst_rate'];
                $hsn[$k] ??= ['sac' => (string) $r['sac'], 'description' => 'Business centre / shared workspace services', 'uqc' => 'NA', 'qty' => 0, 'value' => 0.0,
                    'rate' => (float) $r['gst_rate'], 'taxable' => 0.0, 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'cess' => 0.0];
                $hsn[$k]['qty'] += (int) $r['n'];
                foreach (['value', 'taxable', 'igst', 'cgst', 'sgst'] as $c) {
                    $hsn[$k][$c] = round($hsn[$k][$c] + $sign * (float) $r[$c], 2);
                }
            }
        }
        ksort($hsn);

        // ---- summary
        $sum = static fn (array $rows, string $c) => round(array_sum(array_map(static fn (array $r) => (float) $r[$c], $rows)), 2);
        $cdnr = array_values(array_filter($noteRows, static fn (array $r) => $r['section'] === 'CDNR'));
        $cdnur = array_values(array_filter($noteRows, static fn (array $r) => $r['section'] === 'CDNUR'));
        $line = static function (string $label, int $docsCount, array $rows, int $sign) use ($sum): array {
            $o = ['section' => $label, 'documents' => $docsCount];
            foreach (['taxable', 'igst', 'cgst', 'sgst'] as $c) {
                $o[$c] = round($sign * $sum($rows, $c), 2);
            }
            $o['tax'] = round($o['igst'] + $o['cgst'] + $o['sgst'], 2);
            return $o;
        };
        $summary = [
            $line('B2B — registered recipients (4A)', count($docs['B2B']), $b2b, 1),
            $line('B2CL — unregistered inter-state > ₹1 lakh (5)', count($docs['B2CL']), $b2cl, 1),
            $line('B2CS — other unregistered, net of their credit notes (7)', count($docs['B2CS']) + count($noteDocs['B2CS']), $b2cs, 1),
            $line('CDNR — credit notes to registered recipients (9B)', count($noteDocs['CDNR']), $cdnr, -1),
            $line('CDNUR — credit notes on B2CL invoices (9B)', count($noteDocs['CDNUR']), $cdnur, -1),
        ];
        $check = $this->finance->gst($from, $to);
        $taxableTotal = $sum($summary, 'taxable');
        $notes = [
            sprintf('Return period %s – %s. Place of supply = recipient state (Kerala = 32: CGST + SGST; other states: IGST). Invoice value includes round-off.', format_date($from), format_date($to)),
            sprintf('Net taxable %s and tax %s match the invoice register less credit notes (%s / %s).', number_format($taxableTotal, 2), number_format($sum($summary, 'tax'), 2),
                number_format($check['taxable'], 2), number_format($check['total'], 2)),
        ];
        $money = static fn (string $k, string $l) => Column::money($k, $l);
        $rateCols = [Column::number('rate', 'Rate (%)', false), $money('taxable', 'Taxable value'), $money('igst', 'Integrated tax'), $money('cgst', 'Central tax'), $money('sgst', 'State/UT tax'), $money('cess', 'Cess')];

        return new ReportResult('GST summary', [
            Column::text('section', 'Section', 52), Column::int('documents', 'Documents'), $money('taxable', 'Taxable value'), $money('igst', 'IGST'), $money('cgst', 'CGST'), $money('sgst', 'SGST'), $money('tax', 'Total tax'),
        ], $summary, 'Summary', ['section' => 'Net (invoices − credit notes)'], notes: $notes, subtitle: 'Return period ' . format_date($from, 'd M Y') . ' – ' . format_date($to, 'd M Y'), sheets: [
            new ReportResult('B2B invoices', [
                Column::mono('gstin', 'GSTIN/UIN of recipient', 18), Column::text('name', 'Receiver name'), Column::mono('invoice_no', 'Invoice number', 22), Column::date('invoice_date', 'Invoice date'),
                $money('value', 'Invoice value'), Column::text('pos', 'Place of supply', 18), Column::text('reverse', 'Reverse charge', 9), Column::text('type', 'Invoice type', 13), ...$rateCols,
            ], $b2b, 'B2B', ['value' => $this->distinctValue($b2b, 'invoice_no')]),
            new ReportResult('B2CL invoices', [
                Column::mono('invoice_no', 'Invoice number', 22), Column::date('invoice_date', 'Invoice date'), Column::text('name', 'Recipient'), $money('value', 'Invoice value'),
                Column::text('pos', 'Place of supply', 18), ...$rateCols,
            ], $b2cl, 'B2CL', ['value' => $this->distinctValue($b2cl, 'invoice_no')]),
            new ReportResult('B2CS summary', [
                Column::text('type', 'Type', 8), Column::text('pos', 'Place of supply', 22), ...$rateCols, Column::int('invoices', 'Invoices'), Column::int('notes', 'Credit notes netted'),
            ], $b2cs, 'B2CS'),
            new ReportResult('Credit / debit notes', [
                Column::text('section', 'Section', 14), Column::mono('gstin', 'GSTIN/UIN', 18), Column::text('name', 'Recipient'), Column::mono('note_no', 'Note number', 20), Column::date('note_date', 'Note date'),
                Column::text('note_type', 'Note type', 8), Column::text('pos', 'Place of supply', 18), $money('value', 'Note value'), Column::mono('invoice_no', 'Original invoice', 22),
                Column::date('invoice_date', 'Invoice date'), ...$rateCols,
            ], $noteRows, 'CDNR', ['value' => $this->distinctValue($noteRows, 'note_no')]),
            new ReportResult('HSN / SAC summary', [
                Column::mono('sac', 'HSN/SAC', 10), Column::text('description', 'Description', 34), Column::text('uqc', 'UQC', 6), Column::int('qty', 'Lines'), $money('value', 'Total value'),
                ...$rateCols,
            ], array_values($hsn), 'HSN'),
        ]);
    }

    /**
     * Invoice value counted once per document (rows are per document per rate).
     *
     * @param list<array<string, mixed>> $rows
     */
    private function distinctValue(array $rows, string $key): float
    {
        $seen = [];
        foreach ($rows as $r) {
            $seen[(string) $r[$key]] = (float) $r['value'];
        }
        return round(array_sum($seen), 2);
    }
}
