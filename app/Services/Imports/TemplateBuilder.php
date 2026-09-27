<?php

declare(strict_types=1);

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Downloadable XLSX template per import type:
 *   "Data"          header row (brand fill; required columns end with " *" and have a darker fill + a note with the
 *                   column help), one italic example row (skipped on upload when left unchanged), dropdowns (data
 *                   validation lists from the hidden "Lists" sheet) for option columns, Text format for ID columns
 *                   (mobile, Aadhaar, PIN…), date validation + dd-mm-yyyy format for dates, frozen header
 *   "Instructions"  how to fill it: steps, the importer's own rules and a column-by-column reference
 *   "Lists"         (hidden) the dropdown values
 */
final class TemplateBuilder
{
    public const DATA_ROWS = 1000;

    public function build(Importer $importer): Spreadsheet
    {
        $theme = (array) config('mail.theme', []);
        $brand = ltrim((string) ($theme['brand'] ?? '#1d4ed8'), '#');
        $dark = ltrim((string) ($theme['brand_dark'] ?? '#0b1b3f'), '#');
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Commune Workspace Management System')->setTitle($importer->label() . ' import template')->setCompany('K-DISC');
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $data = $book->getActiveSheet();
        $data->setTitle('Data');
        $lists = new Worksheet($book, 'Lists');
        $book->addSheet($lists);
        $cols = $importer->columns();
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $end = self::DATA_ROWS + 1;
        $listCol = 0;
        foreach ($cols as $i => $c) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $data->setCellValueExplicit($col . '1', $c->label(), DataType::TYPE_STRING);
            $data->getColumnDimension($col)->setWidth(max($c->width, mb_strlen($c->label()) + 3));
            $style = $data->getStyle($col . '1');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($c->required ? $dark : $brand);
            if ($c->help !== '' || $c->options !== []) {
                $comment = $data->getComment($col . '1');
                $comment->setAuthor('Commune');
                $comment->getText()->createTextRun(($c->required ? 'Required. ' : 'Optional. ') . $c->help);
                $comment->setWidth('260pt');
                $comment->setHeight('90pt');
            }
            // example row
            if ($c->example !== '') {
                $data->setCellValueExplicit($col . '2', $c->example, DataType::TYPE_STRING);
            }
            $range = "{$col}2:{$col}{$end}";
            if ($c->type === 'id' || $c->type === 'text' || $c->type === 'time') {
                $data->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            if ($c->type === 'date') {
                $data->getStyle($range)->getNumberFormat()->setFormatCode('dd-mm-yyyy');
            }
            if ($c->options !== []) {
                $listCol++;
                $lc = Coordinate::stringFromColumnIndex($listCol);
                $lists->setCellValue($lc . '1', $c->header);
                foreach (array_values($c->options) as $k => $opt) {
                    $lists->setCellValueExplicit($lc . ($k + 2), $opt, DataType::TYPE_STRING);
                }
                $name = 'list_' . $c->key;
                $book->addNamedRange(new NamedRange($name, $lists, sprintf('$%s$2:$%s$%d', $lc, $lc, count($c->options) + 1)));
                $v = $data->getCell($col . '2')->getDataValidation();
                $v->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(!$c->required)
                    ->setShowDropDown(true)->setShowErrorMessage(true)->setShowInputMessage(true)
                    ->setErrorTitle('Pick from the list')->setError('Choose a value from the dropdown.')
                    ->setPromptTitle($c->header)->setPrompt(mb_substr($c->help !== '' ? $c->help : 'Choose from the list.', 0, 250))
                    ->setFormula1($name);
                $data->setDataValidation($range, $v);
            } elseif ($c->type === 'date') {
                $v = $data->getCell($col . '2')->getDataValidation();
                $v->setType(DataValidation::TYPE_DATE)->setOperator(DataValidation::OPERATOR_GREATERTHAN)->setFormula1('DATE(2020,1,1)')
                    ->setAllowBlank(!$c->required)->setShowErrorMessage(true)->setErrorTitle('Date')->setError('Enter a date as dd-mm-yyyy.')
                    ->setShowInputMessage(true)->setPromptTitle($c->header)->setPrompt(mb_substr($c->help, 0, 250));
                $data->setDataValidation($range, $v);
            }
        }
        $hs = $data->getStyle("A1:{$last}1");
        $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hs->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $data->getRowDimension(1)->setRowHeight(32);
        $ex = $data->getStyle("A2:{$last}2");
        $ex->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
        $data->freezePane('A2');
        $data->setSelectedCell('A3');
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $this->instructions($book, $importer, $dark);
        $book->setActiveSheetIndex(0);
        return $book;
    }

    private function instructions(Spreadsheet $book, Importer $importer, string $dark): void
    {
        $ws = new Worksheet($book, 'Instructions');
        $book->addSheet($ws, 1);
        $ws->getColumnDimension('A')->setWidth(28);
        $ws->getColumnDimension('B')->setWidth(12);
        $ws->getColumnDimension('C')->setWidth(90);
        $r = 1;
        $ws->setCellValue('A1', $importer->label() . ' — bulk import template');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($dark);
        $r = 3;
        $steps = [
            'Fill one row per record on the "Data" sheet, starting at row 3 (row 2 is an example — delete it or leave it unchanged; an unchanged example row is ignored).',
            'Columns whose header ends with * are required. Hover a header for help; use the dropdowns where offered (typing a listed value also works).',
            'Dates: dd-mm-yyyy (or a real Excel date). Keep ID columns (mobile, Aadhaar, PIN, PAN, GSTIN) as text.',
            'Save as .xlsx (max ' . (int) setting('import_max_mb', 5) . ' MB, ' . (int) setting('import_max_rows', 1000) . ' rows) and upload it on Staff console → Bulk upload. Every row is validated with the same rules as the forms; you see a preview before anything is saved.',
            'Rows with errors are never imported. Download the error report (your rows + an "Error" column), fix them and upload just those rows again.',
        ];
        foreach ([...$steps, ...$importer->instructions()] as $i => $line) {
            $ws->setCellValue('A' . $r, ($i + 1) . '.');
            $ws->mergeCells("B{$r}:C{$r}");
            $ws->setCellValue('B' . $r, $line);
            $ws->getStyle('B' . $r)->getAlignment()->setWrapText(true);
            $ws->getRowDimension($r)->setRowHeight(max(15, 15 * (int) ceil(mb_strlen($line) / 105)));
            $r++;
        }
        $r++;
        foreach (['Column', 'Required', 'What to enter'] as $i => $h) {
            $cell = Coordinate::stringFromColumnIndex($i + 1) . $r;
            $ws->setCellValue($cell, $h);
        }
        $ws->getStyle("A{$r}:C{$r}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws->getStyle("A{$r}:C{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($dark);
        foreach ($importer->columns() as $c) {
            $r++;
            $ws->setCellValue('A' . $r, $c->header);
            $ws->setCellValue('B' . $r, $c->required ? 'Yes' : '');
            $text = trim($c->help . ($c->options !== [] && count($c->options) <= 12 ? ' Allowed: ' . implode(', ', $c->options) . '.' : ($c->options !== [] ? ' Pick from the dropdown.' : '')) . ($c->example !== '' ? ' Example: ' . $c->example : ''));
            $ws->setCellValue('C' . $r, $text);
            $ws->getStyle('C' . $r)->getAlignment()->setWrapText(true);
            $ws->getStyle("A{$r}:C{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        }
    }
}
