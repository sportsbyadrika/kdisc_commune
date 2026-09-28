<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * The output of one report run: columns + rows + totals (+ optional extra sheets and a chart payload for the page).
 * HTML (staff/reports/show), PDF (pdf/report) and XLSX (Export\XlsxExporter) all render THIS object.
 *
 *   totals   column key => value; money/int/number columns flagged `total` are summed automatically, a report may
 *            add others (e.g. the overall occupancy % for a pct column). null = no totals row.
 *   sheets   further tables of the same report (GST summary: B2B / B2CS / notes / HSN) — XLSX writes one worksheet
 *            each, the page shows them as tabs and the PDF prints them one after the other.
 */
final class ReportResult
{
    /** @var array<string, float|int|string|null>|null */
    public readonly ?array $totals;

    /**
     * @param list<Column> $columns
     * @param list<array<string, mixed>> $rows
     * @param array<string, float|int|string|null> $extraTotals
     * @param list<string> $notes
     * @param array<string, mixed>|null $chart chart payload for resources/js/dashboards.js (HTML only)
     * @param list<ReportResult> $sheets
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly string $sheetName = 'Report',
        array $extraTotals = [],
        bool $withTotals = true,
        public readonly array $notes = [],
        public readonly ?array $chart = null,
        public readonly array $sheets = [],
        public readonly string $subtitle = '',
    ) {
        $totals = null;
        if ($withTotals && ($extraTotals !== [] || array_filter($columns, static fn (Column $c) => $c->total) !== [])) {
            $totals = [];
            foreach ($columns as $c) {
                if ($c->total) {
                    $sum = 0.0;
                    foreach ($rows as $r) {
                        $sum += (float) ($r[$c->key] ?? 0);
                    }
                    $totals[$c->key] = $c->type === 'int' ? (int) round($sum) : round($sum, 2);
                }
            }
            $totals = $extraTotals + $totals;
        }
        $this->totals = $totals;
    }

    /** @return list<ReportResult> this table followed by the extra sheets */
    public function allSheets(): array
    {
        return [$this, ...$this->sheets];
    }

    public function column(string $key): ?Column
    {
        foreach ($this->columns as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Rows sorted by a column (HTML table sort; stable, nulls last).
     *
     * @return list<array<string, mixed>>
     */
    public function sorted(string $key, string $dir): array
    {
        $col = $this->column($key);
        if ($col === null) {
            return $this->rows;
        }
        $rows = $this->rows;
        $sign = $dir === 'desc' ? -1 : 1;
        $indexed = [];
        foreach ($rows as $i => $r) {
            $indexed[] = [$i, $r];
        }
        usort($indexed, static function (array $a, array $b) use ($col, $sign): int {
            $va = $a[1][$col->key] ?? null;
            $vb = $b[1][$col->key] ?? null;
            if ($va === null || $va === '') {
                return ($vb === null || $vb === '') ? $a[0] <=> $b[0] : 1;
            }
            if ($vb === null || $vb === '') {
                return -1;
            }
            $cmp = $col->numeric() ? ((float) $va <=> (float) $vb) : strnatcasecmp((string) $va, (string) $vb);
            return $cmp !== 0 ? $cmp * $sign : $a[0] <=> $b[0];
        });
        return array_map(static fn (array $x) => $x[1], $indexed);
    }
}
