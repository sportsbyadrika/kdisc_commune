<?php
/**
 * Finance register PDF (A4 landscape) — RegisterController::pdf().
 *
 * @var App\Core\Template $this
 * @var array{title: string, subtitle: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, float>} $register
 * @var string $generatedAt
 * @var string $by
 * @var array<string, string> $supplier
 */
$this->layout('pdf/layout', ['title' => $register['title'], 'extraCss' => '@page { margin: 12mm 10mm 14mm 10mm; } body { font-size: 7.2pt; } .grid td, .grid th { padding: 3pt 3.5pt; }']);
$cell = static fn (array $c, mixed $v): string => match ($c['type']) {
    'money' => number_format((float) $v, 2),
    'date' => $v ? format_date((string) $v, 'd-m-Y') : '—',
    default => $v === null || $v === '' ? '—' : (string) $v,
};
?>
<table class="head">
    <tr>
        <td><div class="org-name"><?= e($register['title']) ?></div><div class="org-sub"><?= e($supplier['legal_name'] ?? '') ?><?= !empty($supplier['gstin']) ? ' · GSTIN ' . e($supplier['gstin']) : '' ?> · <?= e($supplier['trade_name'] ?? '') ?></div></td>
        <td class="r"><div class="b" style="color:#0b1b3f;"><?= e($register['subtitle']) ?></div><div class="org-sub">Generated <?= e($generatedAt) ?><?= $by !== '' ? ' by ' . e($by) : '' ?> · <?= count($register['rows']) ?> rows</div></td>
    </tr>
</table>
<div class="rule"></div><div class="rule-accent"></div>
<table class="grid mt8">
    <thead><tr><th style="width: 14pt;">#</th><?php foreach ($register['columns'] as $c): ?><th class="<?= $c['type'] === 'money' ? 'r' : '' ?>"><?= e($c['label']) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($register['rows'] as $i => $r): ?>
        <tr class="<?= ($r['status'] ?? '') === 'cancelled' ? 'cancelled' : '' ?>"><td class="muted"><?= $i + 1 ?></td><?php foreach ($register['columns'] as $c): ?><td class="<?= $c['type'] === 'money' ? 'r nowrap' : ($c['type'] === 'mono' ? 'mono nowrap' : ($c['type'] === 'date' ? 'nowrap' : '')) ?>"><?= e($cell($c, $r[$c['key']] ?? null)) ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    <?php if ($register['rows'] === []): ?><tr><td colspan="<?= count($register['columns']) + 1 ?>" class="c muted">No entries for this period.</td></tr><?php endif ?>
    </tbody>
    <tfoot><tr><td></td><?php foreach ($register['columns'] as $i => $c): ?><td class="<?= $c['type'] === 'money' ? 'r nowrap' : '' ?>"><?= isset($register['totals'][$c['key']]) ? e(number_format($register['totals'][$c['key']], 2)) : ($i === 0 ? 'Total' : '') ?></td><?php endforeach ?></tr></tfoot>
</table>
