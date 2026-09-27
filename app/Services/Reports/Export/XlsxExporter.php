<?php

declare(strict_types=1);

namespace App\Services\Reports\Export;

use App\Services\Reports\Column;
use App\Services\Reports\ReportResult;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes a ReportResult (and its extra sheets) as a styled workbook — the XLSX side of the report definitions.
 *
 * Every sheet: title row, "generated at / by" row, filter + note rows, then the table with a brand-coloured header
 * row (frozen, autofilter), typed cells (₹ amounts `#,##0.00`, integers `#,##0`, percentages `0.0%`, real Excel
 * dates `dd-mm-yyyy`), text/ID columns written as strings (mobile numbers, GSTINs keep their digits), column
 * widths from Column::xlsxWidth(), and a bold totals row using SUBTOTAL(9, …) so it follows the autofilter.
 * Print setup: landscape, fit to one page wide, header row repeated.
 */
final class XlsxExporter
{
    public const MONEY = '#,##0.00';
    public const INT = '#,##0';
    public const NUMBER = '#,##0.00';
    public const PCT = '0.0%';
    public const DATE = 'dd-mm-yyyy';
    public const DATETIME = 'dd-mm-yyyy hh:mm';

    /**
     * @param list<array{0: string, 1: string}> $filterLines ReportFilters::describe()
     */
    public function workbook(ReportResult $result, array $filterLines, string $generatedAt, string $by): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Commune Workspace Management System')->setTitle($result->title)->setCompany('K-DISC');
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $book->removeSheetByIndex(0);
        foreach ($result->allSheets() as $i => $sheet) {
            $ws = new Worksheet($book, self::sheetTitle($sheet->sheetName, $i));
            $book->addSheet($ws);
            $this->writeSheet($ws, $sheet, $i === 0 ? $result->title : $result->title . ' — ' . $sheet->title, $filterLines, $generatedAt, $by);
        }
        $book->setActiveSheetIndex(0);
        return $book;
    }

    /**
     * @param list<array{0: string, 1: string}> $filterLines
     */
    public function writeSheet(Worksheet $ws, ReportResult $r, string $title, array $filterLines, string $generatedAt, string $by): int
    {
        $theme = (array) config('mail.theme', []);
        $brand = ltrim((string) ($theme['brand'] ?? '#1d4ed8'), '#');
        $ink = ltrim((string) ($theme['brand_dark'] ?? '#0b1b3f'), '#');
        $muted = ltrim((string) ($theme['muted'] ?? '#6b7280'), '#');
        $line = ltrim((string) ($theme['line'] ?? '#e5e7eb'), '#');
        $n = max(1, count($r->columns));
        $lastCol = Coordinate::stringFromColumnIndex($n);

        $row = 1;
        $ws->setCellValue('A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($ink);
        $row++;
        $ws->setCellValue('A' . $row, trim((string) config('app.name', 'Commune') . ' · K-DISC — generated ' . $generatedAt . ($by !== '' ? ' by ' . $by : '')));
        $ws->getStyle('A' . $row)->getFont()->getColor()->setRGB($muted);
        if ($r->subtitle !== '') {
            $row++;
            $ws->setCellValue('A' . $row, $r->subtitle);
        }
        if ($filterLines !== []) {
            $row++;
            $ws->setCellValue('A' . $row, 'Filters: ' . implode(' · ', array_map(static fn (array $l) => $l[0] . ': ' . $l[1], $filterLines)));
        }
        foreach ($r->notes as $note) {
            $row++;
            $ws->setCellValue('A' . $row, $note);
            $ws->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setRGB($muted);
        }
        $row += 2;

        // header
        $header = $row;
        foreach ($r->columns as $i => $c) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValueExplicit($col . $header, $c->label, DataType::TYPE_STRING);
            $ws->getColumnDimension($col)->setWidth($c->xlsxWidth());
            if ($c->numeric()) {
                $ws->getStyle($col . $header)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
        $hs = $ws->getStyle("A{$header}:{$lastCol}{$header}");
        $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hs->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brand);
        $hs->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getRowDimension($header)->setRowHeight(30);

        // data
        $first = $header + 1;
        $row = $header;
        foreach ($r->rows as $data) {
            $row++;
            foreach ($r->columns as $i => $c) {
                $this->writeCell($ws, Coordinate::stringFromColumnIndex($i + 1) . $row, $c, $data[$c->key] ?? null);
            }
        }
        $last = max($row, $first - 1);
        if ($r->rows !== []) {
            foreach ($r->columns as $i => $c) {
                $fmt = self::format($c);
                if ($fmt !== null) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $ws->getStyle("{$col}{$first}:{$col}{$last}")->getNumberFormat()->setFormatCode($fmt);
                }
            }
            $ws->getStyle("A{$first}:{$lastCol}{$last}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB($line);
        }
        $ws->setAutoFilter("A{$header}:{$lastCol}" . max($header + 1, $last));
        $ws->freezePane('A' . ($header + 1));

        // totals
        if ($r->totals !== null) {
            $t = $last + 1;
            $overrides = $this->overrides($r);
            $labelAt = null;
            foreach ($r->columns as $i => $c) {
                if (!$c->numeric() && ($r->totals[$c->key] ?? null) === null) {
                    $labelAt = $i;
                    break;
                }
            }
            foreach ($r->columns as $i => $c) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $v = $r->totals[$c->key] ?? null;
                if ($c->total && $r->rows !== [] && !array_key_exists($c->key, $overrides)) {
                    $ws->setCellValue("{$col}{$t}", "=SUBTOTAL(9,{$col}{$first}:{$col}{$last})");
                } elseif ($v !== null) {
                    $this->writeCell($ws, "{$col}{$t}", $c, $v);
                } elseif ($i === $labelAt && !isset($r->totals[$r->columns[0]->key])) {
                    $ws->setCellValue("{$col}{$t}", 'Total');
                }
                $fmt = self::format($c);
                if ($fmt !== null) {
                    $ws->getStyle("{$col}{$t}")->getNumberFormat()->setFormatCode($fmt);
                }
            }
            $ts = $ws->getStyle("A{$t}:{$lastCol}{$t}");
            $ts->getFont()->setBold(true);
            $ts->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($ink);
            $ts->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7F7F9');
        }
        if ($r->rows === []) {
            $ws->setCellValue('A' . $first, 'No rows for these filters.');
            $ws->getStyle('A' . $first)->getFont()->setItalic(true)->getColor()->setRGB($muted);
        }

        $setup = $ws->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd($header, $header);
        $ws->getHeaderFooter()->setOddFooter('&L' . $title . '&RPage &P of &N');
        $ws->setSelectedCell('A' . ($header + 1));
        return $header;
    }

    /**
     * Report-supplied totals that must be written as values (not SUBTOTAL formulas).
     *
     * @return array<string, mixed>
     */
    private function overrides(ReportResult $r): array
    {
        $auto = [];
        foreach ($r->columns as $c) {
            if ($c->total) {
                $sum = 0.0;
                foreach ($r->rows as $row) {
                    $sum += (float) ($row[$c->key] ?? 0);
                }
                $auto[$c->key] = round($sum, 2);
            }
        }
        $out = [];
        foreach ((array) $r->totals as $k => $v) {
            if (isset($auto[$k]) && abs((float) $v - $auto[$k]) > 0.001) {
                $out[$k] = $v; // e.g. GST invoice value counted once per document
            }
        }
        return $out;
    }

    private function writeCell(Worksheet $ws, string $cell, Column $c, mixed $v): void
    {
        if ($v === null || $v === '') {
            return;
        }
        switch ($c->type) {
            case 'money':
            case 'number':
            case 'pct':
                $ws->setCellValueExplicit($cell, (float) $v, DataType::TYPE_NUMERIC);
                return;
            case 'int':
                $ws->setCellValueExplicit($cell, (int) round((float) $v), DataType::TYPE_NUMERIC);
                return;
            case 'date':
            case 'datetime':
                $ts = strtotime((string) $v);
                if ($ts !== false) {
                    $excel = XlsDate::PHPToExcel(new \DateTimeImmutable($c->type === 'date' ? date('Y-m-d', $ts) : date('Y-m-d H:i:s', $ts)));
                    $ws->setCellValueExplicit($cell, $excel, DataType::TYPE_NUMERIC);
                    return;
                }
                break;
        }
        // explicit string type: IDs keep leading zeros / digits and user text can never become a formula
        $ws->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
    }

    public static function format(Column $c): ?string
    {
        return match ($c->type) {
            'money' => self::MONEY,
            'int' => self::INT,
            'number' => self::NUMBER,
            'pct' => self::PCT,
            'date' => self::DATE,
            'datetime' => self::DATETIME,
            default => null,
        };
    }

    /** Excel sheet names: ≤ 31 chars, no []:*?/\ */
    public static function sheetTitle(string $name, int $i = 0): string
    {
        $t = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name));
        return mb_substr($t !== '' ? $t : 'Sheet ' . ($i + 1), 0, 31);
    }

    public function bytes(Spreadsheet $book): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary file for the XLSX export.');
        }
        try {
            (new Xlsx($book))->save($tmp);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }
    }
}
