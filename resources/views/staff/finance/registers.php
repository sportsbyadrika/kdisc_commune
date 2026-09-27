<?php
/**
 * Finance registers with PDF export (FinanceReportService::register()).
 *
 * @var App\Core\Template $this
 * @var string $type
 * @var array<string, string> $filters fy, from, to
 * @var list<string> $fys
 * @var array{title: string, subtitle: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, float>} $register
 */
use App\Services\Finance\FinanceReportService;

$this->layout('layouts/staff', ['title' => 'Finance registers', 'subtitle' => 'Statutory-style registers by financial year or date range — export any of them as PDF.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Finance'], ['Registers']]]);
$query = array_filter(['type' => $type] + $filters, static fn ($v) => $v !== '');
$cell = static function (array $c, mixed $v): string {
    return match ($c['type']) {
        'money' => e(number_format((float) $v, 2)),
        'date' => e($v ? format_date((string) $v, 'd M Y') : '—'),
        default => e($v === null || $v === '' ? '—' : (string) $v),
    };
};
?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.registers.pdf', array_diff_key($query, ['type' => 1]) + ['type' => $type])) ?>" class="btn btn-brand" target="_blank" rel="noopener"><?= icon('file-down', 'size-4') ?>Export PDF</a>
<?php $this->stop() ?>

<nav class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Registers">
    <div class="flex w-max gap-2 pb-1">
        <?php foreach (FinanceReportService::REGISTERS as $key => [$label, $ic]): $on = $key === $type; ?>
            <a href="<?= e(url('staff.registers.index', ['type' => $key] + array_diff_key($query, ['type' => 1]))) ?>" class="chip <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= icon($ic, 'size-4') ?><?= e($label) ?></a>
        <?php endforeach ?>
    </div>
</nav>

<?php if ($type !== 'outstanding'): ?>
<form method="get" class="card mb-5 flex flex-wrap items-end gap-3 p-3 sm:p-4">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?= $this->component('select', ['name' => 'fy', 'label' => 'Financial year', 'value' => $filters['fy'], 'options' => array_combine($fys, array_map(static fn ($f) => 'FY ' . $f, $fys))]) ?>
    <span class="pb-3 text-sm text-muted">or</span>
    <?= $this->component('input', ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => $filters['from']]) ?>
    <?= $this->component('input', ['name' => 'to', 'label' => 'To', 'type' => 'date', 'value' => $filters['to']]) ?>
    <button class="btn btn-brand"><?= icon('filter', 'size-4') ?>Apply</button>
    <?php if ($filters['from'] !== ''): ?><a class="pb-3 text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.registers.index', ['type' => $type, 'fy' => $filters['fy']])) ?>">Clear dates</a><?php endif ?>
</form>
<?php endif ?>

<div class="mb-3 flex items-baseline justify-between gap-3">
    <h2 class="text-lg font-bold"><?= e($register['title']) ?> <span class="text-sm font-normal text-muted">· <?= e($register['subtitle']) ?></span></h2>
    <p class="text-sm text-muted"><?= count($register['rows']) ?> row<?= count($register['rows']) === 1 ? '' : 's' ?></p>
</div>
<?php if ($register['rows'] === []): ?>
    <?= $this->component('empty', ['icon' => FinanceReportService::REGISTERS[$type][1], 'title' => 'No entries', 'text' => 'Nothing recorded for this period.']) ?>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><?php foreach ($register['columns'] as $c): ?><th class="<?= $c['type'] === 'money' ? 'text-right' : '' ?>"><?= e($c['label']) ?></th><?php endforeach ?></tr></thead>
            <tbody>
            <?php foreach ($register['rows'] as $r): ?>
                <tr>
                    <?php foreach ($register['columns'] as $c): ?>
                        <td class="<?= $c['type'] === 'money' ? 'text-right tabular-nums' : ($c['type'] === 'mono' ? 'font-mono text-xs' : '') ?> <?= $c['key'] === 'customer_name' ? 'max-w-56 truncate font-semibold' : '' ?>"><?= $cell($c, $r[$c['key']] ?? null) ?></td>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>
            </tbody>
            <tfoot class="bg-surface text-sm font-bold">
                <tr><?php foreach ($register['columns'] as $i => $c): ?><td class="px-4 py-3 <?= $c['type'] === 'money' ? 'text-right tabular-nums' : '' ?>"><?= isset($register['totals'][$c['key']]) ? e(number_format($register['totals'][$c['key']], 2)) : ($i === 0 ? 'Total' : '') ?></td><?php endforeach ?></tr>
            </tfoot>
        </table>
    </div>
<?php endif ?>
