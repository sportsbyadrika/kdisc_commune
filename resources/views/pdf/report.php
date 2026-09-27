<?php
/**
 * Report PDF (A4 landscape) — Reports\Export\ReportExporter::pdfBytes(). Renders the same ReportResult as the page and
 * the XLSX: every sheet as a table with its totals row, then the notes.
 *
 * @var App\Core\Template $this
 * @var App\Services\Reports\ReportResult $result
 * @var list<array{0: string, 1: string}> $filterLines
 * @var string $generatedAt
 * @var string $by
 * @var array<string, string> $supplier
 * @var int $maxRows
 */
$this->layout('pdf/layout', ['title' => $result->title, 'extraCss' => '@page { margin: 11mm 10mm 14mm 10mm; } body { font-size: 7.2pt; } .grid td, .grid th { padding: 2.8pt 3.4pt; } .sheet-title { font-size: 9.5pt; font-weight: bold; color: #0b1b3f; margin: 12pt 0 0 0; } .filters td { padding: 1pt 8pt 1pt 0; } .note { color: #6b7280; font-size: 6.8pt; margin-top: 3pt; } .grid.dense td, .grid.dense th { font-size: 5.9pt; padding: 2pt 2.4pt; } .grid.dense td.mono { font-size: 5.6pt; } .grid.dense td { word-wrap: break-word; } .page-foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 6.4pt; color: #9ca3af; }']);
?>
<div class="page-foot"><?= e($supplier['legal_name'] ?? '') ?> · <?= e($result->title) ?> · generated <?= e($generatedAt) ?></div>
<table class="head">
    <tr>
        <td>
            <div class="org-name"><?= e($result->title) ?></div>
            <div class="org-sub"><?= e($supplier['legal_name'] ?? '') ?><?= !empty($supplier['trade_name']) ? ' · ' . e($supplier['trade_name']) : '' ?><?= !empty($supplier['gstin']) ? ' · GSTIN ' . e($supplier['gstin']) : '' ?></div>
        </td>
        <td class="r">
            <?php if ($result->subtitle !== ''): ?><div class="b" style="color:#0b1b3f;"><?= e($result->subtitle) ?></div><?php endif ?>
            <div class="org-sub">Generated <?= e($generatedAt) ?><?= $by !== '' ? ' by ' . e($by) : '' ?></div>
        </td>
    </tr>
</table>
<div class="rule"></div><div class="rule-accent"></div>
<?php if ($filterLines !== []): ?>
<table class="filters mt8" style="width:auto;"><tr>
    <?php foreach ($filterLines as [$k, $v]): ?><td><span class="muted"><?= e($k) ?>:</span> <span class="b"><?= e($v) ?></span></td><?php endforeach ?>
</tr></table>
<?php endif ?>

<?php foreach ($result->allSheets() as $si => $sheet):
    $rows = array_slice($sheet->rows, 0, $maxRows);
    $dense = count($sheet->columns) > 10; // wide lists: smaller type, IDs may wrap so the table fits the page
?>
    <?php if ($si > 0 || $result->sheets !== []): ?><div class="sheet-title"><?= e($sheet->title) ?> <span class="muted" style="font-weight:normal;">· <?= count($sheet->rows) ?> row<?= count($sheet->rows) === 1 ? '' : 's' ?></span></div><?php endif ?>
    <table class="grid mt8 <?= $dense ? 'dense' : '' ?>">
        <thead><tr><?php foreach ($sheet->columns as $c): ?><th class="<?= $c->numeric() ? 'r' : '' ?>"><?= e($c->label) ?></th><?php endforeach ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($sheet->columns as $c): ?><td class="<?= $c->numeric() ? 'r nowrap' : ($c->type === 'mono' ? 'mono' . ($dense ? '' : ' nowrap') : (in_array($c->type, ['date', 'datetime'], true) ? 'nowrap' : '')) ?>"><?= e($c->format($r[$c->key] ?? null)) ?></td><?php endforeach ?></tr>
        <?php endforeach ?>
        <?php if ($rows === []): ?><tr><td colspan="<?= count($sheet->columns) ?>" class="c muted">No rows for these filters.</td></tr><?php endif ?>
        </tbody>
        <?php if ($sheet->totals !== null && $rows !== []): $labelled = false; ?>
            <tfoot><tr><?php foreach ($sheet->columns as $i => $c):
                $v = $sheet->totals[$c->key] ?? null;
                $text = $v !== null ? $c->format($v) : (!$labelled && !$c->numeric() && !isset($sheet->totals[$sheet->columns[0]->key]) ? 'Total' : '');
                $labelled = $labelled || $text === 'Total';
            ?><td class="<?= $c->numeric() ? 'r nowrap' : '' ?>"><?= e($text) ?></td><?php endforeach ?></tr></tfoot>
        <?php endif ?>
    </table>
    <?php if (count($sheet->rows) > $maxRows): ?><p class="note">Showing the first <?= (int) $maxRows ?> of <?= count($sheet->rows) ?> rows — export XLSX for the complete list.</p><?php endif ?>
<?php endforeach ?>
<?php foreach ($result->notes as $note): ?><p class="note"><?= e($note) ?></p><?php endforeach ?>
