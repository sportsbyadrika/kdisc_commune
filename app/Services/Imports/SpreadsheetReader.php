<?php

declare(strict_types=1);

namespace App\Services\Imports;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

/**
 * Reads the first worksheet of an uploaded template in CHUNKS (ReadFilter of CHUNK rows at a time, data only), so a
 * big file never sits in memory as one Spreadsheet object. Row 1 = headers, matched to the importer's columns by
 * their normalised text (the " *" required marker and case are ignored; unknown headers are reported). Values come
 * back as trimmed strings: real Excel dates → Y-m-d, typed dates dd-mm-yyyy / dd/mm/yyyy / yyyy-mm-dd → Y-m-d, times
 * → H:i, whole numbers without ".0". Empty rows and the template's example row are skipped.
 */
final class SpreadsheetReader
{
    public const CHUNK = 250;

    /**
     * @param list<ImportColumn> $columns
     * @return array{rows: list<array<string, string>>, missing: list<string>, unknown: list<string>, truncated: bool}
     *         each row has `_row` = the sheet row number
     */
    public function read(string $path, array $columns, int $maxRows): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $sheets = $reader->listWorksheetInfo($path);
        $first = $sheets[0] ?? null;
        if ($first === null) {
            throw new ImportException('The workbook has no worksheets.');
        }
        $sheetName = (string) $first['worksheetName'];
        $lastRow = (int) $first['totalRows'];
        $reader->setLoadSheetsOnly($sheetName);

        $filter = new class () implements IReadFilter {
            public int $start = 1;
            public int $end = 1;

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1 || ($row >= $this->start && $row <= $this->end);
            }
        };
        $reader->setReadFilter($filter);

        $byHeader = [];
        foreach ($columns as $c) {
            $byHeader[ImportColumn::normalizeHeader($c->header)] = $c;
        }
        $map = [];   // column letter index => ImportColumn
        $unknown = [];
        $rows = [];
        $truncated = false;
        $examples = [];
        foreach ($columns as $c) {
            $examples[$c->key] = $c->example;
        }

        for ($start = 2; $start <= max(2, $lastRow); $start += self::CHUNK) {
            $filter->start = $start;
            $filter->end = $start + self::CHUNK - 1;
            $book = $reader->load($path);
            $ws = $book->getSheet(0);
            if ($map === []) {
                $headerRow = $ws->rangeToArray('A1:' . $ws->getHighestColumn() . '1', null, false, false, false)[0] ?? [];
                foreach ($headerRow as $i => $h) {
                    $n = ImportColumn::normalizeHeader((string) $h);
                    if ($n === '') {
                        continue;
                    }
                    if (isset($byHeader[$n])) {
                        $map[$i] = $byHeader[$n];
                    } else {
                        $unknown[] = (string) $h;
                    }
                }
                if ($map === []) {
                    $book->disconnectWorksheets();
                    throw new ImportException('No known column headers in row 1 — use the template for this import type.');
                }
            }
            $highest = $ws->getHighestDataRow();
            for ($r = $start; $r <= min($filter->end, $highest); $r++) {
                $row = ['_row' => (string) $r];
                $empty = true;
                foreach ($map as $i => $col) {
                    $cell = $ws->getCell([$i + 1, $r]);
                    $v = self::value($cell->getValue(), $col);
                    $row[$col->key] = $v;
                    $empty = $empty && $v === '';
                }
                if ($empty || self::isExample($row, $examples)) {
                    continue;
                }
                if (count($rows) >= $maxRows) {
                    $truncated = true;
                    break 2;
                }
                $rows[] = $row;
            }
            $book->disconnectWorksheets();
            unset($book, $ws);
            if ($highest < $filter->end) {
                break;
            }
        }
        $found = array_map(static fn (ImportColumn $c) => $c->key, $map);
        $missing = [];
        foreach ($columns as $c) {
            if ($c->required && !in_array($c->key, $found, true)) {
                $missing[] = $c->label();
            }
        }
        return ['rows' => $rows, 'missing' => $missing, 'unknown' => $unknown, 'truncated' => $truncated];
    }

    public static function value(mixed $v, ImportColumn $col): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (is_int($v) || is_float($v)) {
            if ($col->type === 'date' && $v > 20000 && $v < 80000) {
                return XlsDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            }
            if ($col->type === 'time' && $v >= 0 && $v < 1) {
                $mins = (int) round($v * 1440);
                return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
            }
            return floor((float) $v) === (float) $v && abs((float) $v) < 1e15 ? sprintf('%.0f', $v) : rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        if ($col->type === 'date' && $s !== '') {
            foreach (['!d-m-Y', '!d/m/Y', '!d.m.Y', '!Y-m-d', '!d-M-Y', '!d M Y'] as $fmt) {
                $d = DateTimeImmutable::createFromFormat($fmt, $s);
                if ($d !== false && $d->format(ltrim($fmt, '!')) === $s) {
                    return $d->format('Y-m-d');
                }
            }
            return $s; // left as typed → the importer reports it
        }
        if ($col->type === 'time' && preg_match('/^(\d{1,2})[:.](\d{2})$/', $s, $m)) {
            return sprintf('%02d:%s', (int) $m[1], $m[2]);
        }
        return $s;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $examples
     */
    private static function isExample(array $row, array $examples): bool
    {
        $nonEmpty = 0;
        foreach ($examples as $k => $ex) {
            $v = $row[$k] ?? '';
            if ($v === '' && $ex === '') {
                continue;
            }
            $nonEmpty++;
            if (preg_replace('/\s+/', '', mb_strtolower($v)) !== preg_replace('/\s+/', '', mb_strtolower($ex))) {
                // dates: the example is dd-mm-yyyy, the row is Y-m-d
                $d = DateTimeImmutable::createFromFormat('!d-m-Y', $ex);
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    return false;
                }
            }
        }
        return $nonEmpty > 0;
    }
}
