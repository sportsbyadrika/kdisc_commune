<?php
/**
 * One report: filters (from the definition), chart, sortable paginated table with totals, and XLSX / PDF exports
 * that carry the same filters (ReportController).
 *
 * @var App\Core\Template $this
 * @var App\Services\Reports\Report $report
 * @var App\Services\Reports\ReportFilters $filters
 * @var App\Services\Reports\ReportResult $result
 * @var App\Services\Reports\ReportResult $sheet
 * @var int $sheetNo
 * @var list<array<string, mixed>> $rows
 * @var array{page: int, pages: int, total: int, per_page: int} $pager
 * @var string $sort
 * @var string $dir
 * @var string|null $backUrl
 */
use App\Services\Reports\Filter;
use App\Services\Reports\ReportRegistry;

$q = $filters->query();
$group = ReportRegistry::GROUPS[$report->group()][0] ?? 'Reports';
$this->layout('layouts/staff', [
    'title' => $report->title(),
    'subtitle' => $report->description(),
    'breadcrumb' => $backUrl !== null
        ? [['Dashboard', url('staff.dashboard')], [$report->title(), $backUrl], ['Export']]
        : [['Dashboard', url('staff.dashboard')], ['Reports', url('staff.reports.index')], [$group]],
]);
$link = fn (array $extra) => url('staff.reports.show', ['key' => $report->key()] + $q + $extra);
$sheetQ = $sheetNo > 0 ? ['sheet' => $sheetNo] : [];
?>
<?php $this->start('head') ?>
<?php if ($result->chart !== null): ?>
<script defer src="<?= e(asset('assets/vendor/chart.umd.min.js')) ?>"></script>
<?php endif ?>
<script defer src="<?= e(asset('assets/js/dashboards.js')) ?>"></script>
<?php $this->stop() ?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.reports.xlsx', ['key' => $report->key()] + $q)) ?>" class="btn btn-brand"><?= icon('file-spreadsheet', 'size-4') ?>Export XLSX</a>
<a href="<?= e(url('staff.reports.pdf', ['key' => $report->key()] + $q)) ?>" class="btn btn-outline" target="_blank" rel="noopener"><?= icon('file-down', 'size-4') ?>Export PDF</a>
<?php $this->stop() ?>

<?php if ($report->filters() !== []): ?>
<form method="get" action="<?= e(url('staff.reports.show', ['key' => $report->key()])) ?>" class="card mb-5 flex flex-wrap items-end gap-3 p-3 sm:p-4" aria-label="Report filters">
    <?php foreach ($report->filters() as $f): ?>
        <?php if ($f->kind === 'range'): $custom = $filters->get('range') === 'custom'; ?>
            <?= $this->component('select', ['name' => 'range', 'label' => $f->label, 'value' => $filters->get('range'), 'options' => Filter::PRESETS, 'class' => 'min-w-44', 'attrs' => ['data-range-select' => 'custom-' . $report->key()]]) ?>
            <div id="custom-<?= e($report->key()) ?>" class="flex flex-wrap items-end gap-3" <?= $custom ? '' : 'hidden' ?>>
                <?= $this->component('input', ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => $filters->from()]) ?>
                <?= $this->component('input', ['name' => 'to', 'label' => 'To', 'type' => 'date', 'value' => $filters->to()]) ?>
            </div>
        <?php elseif ($f->kind === 'select'): ?>
            <?= $this->component('select', ['name' => $f->name, 'label' => $f->label, 'value' => $filters->get($f->name), 'options' => $f->options, 'placeholder' => $f->default === '' ? 'All' : null, 'class' => 'min-w-40']) ?>
        <?php elseif ($f->kind === 'date'): ?>
            <?= $this->component('input', ['name' => $f->name, 'label' => $f->label, 'type' => 'date', 'value' => $filters->get($f->name)]) ?>
        <?php else: ?>
            <?= $this->component('input', ['name' => $f->name, 'label' => $f->label, 'type' => 'search', 'value' => $filters->get($f->name), 'class' => 'min-w-56']) ?>
        <?php endif ?>
    <?php endforeach ?>
    <button class="btn btn-brand"><?= icon('list-filter', 'size-4') ?>Apply</button>
    <?php if ($backUrl !== null): ?><a class="btn btn-ghost" href="<?= e($backUrl) ?>"><?= icon('arrow-left', 'size-4') ?>Back to the list</a><?php endif ?>
</form>
<?php endif ?>

<?php if ($result->chart !== null && ($result->chart['values'] ?? []) !== [] && $sheetNo === 0): ?>
<section class="card card-body mb-5">
    <h2 class="text-base font-bold"><?= e($result->chart['label'] ?? $result->title) ?></h2>
    <p class="text-sm text-muted"><?= e($filters->summary()) ?></p>
    <div class="mt-3 <?= in_array($result->chart['kind'], ['hbar', 'hbar-count', 'funnel'], true) ? (count($result->chart['values']) > 6 ? 'h-80' : 'h-56') : 'h-64' ?>">
        <canvas data-dash-chart="<?= e(json_encode($result->chart, JSON_UNESCAPED_UNICODE)) ?>" role="img" aria-label="<?= e(($result->chart['label'] ?? $result->title) . ' chart — the same figures are in the table below') ?>"></canvas>
    </div>
</section>
<?php endif ?>

<?php if ($result->sheets !== []): ?>
<nav class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Report sections">
    <div class="flex w-max gap-2 pb-1">
        <?php foreach ($result->allSheets() as $i => $s): $on = $i === $sheetNo; ?>
            <a href="<?= e($link($i > 0 ? ['sheet' => $i] : [])) ?>" class="chip <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= e($s->title) ?> <span class="<?= $on ? 'text-white/70' : 'text-muted' ?>"><?= count($s->rows) ?></span></a>
        <?php endforeach ?>
    </div>
</nav>
<?php endif ?>

<div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
    <h2 class="text-lg font-bold"><?= e($sheet->title) ?><?php if ($sheet->subtitle !== ''): ?> <span class="text-sm font-normal text-muted">· <?= e($sheet->subtitle) ?></span><?php endif ?></h2>
    <p class="text-sm text-muted"><?= (int) $pager['total'] ?> row<?= $pager['total'] === 1 ? '' : 's' ?></p>
</div>

<?php if ($sheet->rows === []): ?>
    <?= $this->component('empty', ['icon' => $report->icon(), 'title' => 'No rows', 'text' => 'Nothing matches these filters.']) ?>
<?php else: ?>
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <?php foreach ($sheet->columns as $c):
                            $active = $sort === $c->key;
                            $next = $active && $dir === 'asc' ? 'desc' : 'asc';
                        ?>
                            <th scope="col" class="<?= $c->numeric() ? '!text-right' : '' ?>" aria-sort="<?= $active ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="sort-link <?= $c->numeric() ? 'flex-row-reverse' : '' ?>" href="<?= e($link($sheetQ + ['sort' => $c->key, 'dir' => $next])) ?>"><?= e($c->label) ?><?= icon($active ? ($dir === 'asc' ? 'arrow-up' : 'arrow-down') : 'chevrons-up-down', 'size-3.5 ' . ($active ? 'text-brand-600' : 'text-muted/60')) ?></a>
                            </th>
                        <?php endforeach ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-white">
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <?php foreach ($sheet->columns as $i => $c): ?>
                                <td class="<?= e(class_names([
                                    'text-right tabular-nums whitespace-nowrap' => $c->numeric(),
                                    'font-mono text-xs whitespace-nowrap' => $c->type === 'mono',
                                    'whitespace-nowrap' => in_array($c->type, ['date', 'datetime'], true),
                                    'font-semibold' => $i === 0 && !$c->numeric(),
                                ])) ?>"><?= e($c->format($r[$c->key] ?? null)) ?></td>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <?php if ($sheet->totals !== null): $labelled = false; ?>
                    <tfoot class="bg-surface text-sm font-bold">
                        <tr>
                            <?php foreach ($sheet->columns as $c):
                                $v = $sheet->totals[$c->key] ?? null;
                                $text = $v !== null ? $c->format($v) : (!$labelled && !$c->numeric() && !isset($sheet->totals[$sheet->columns[0]->key]) ? ($pager['pages'] > 1 ? 'Total (all rows)' : 'Total') : '');
                                $labelled = $labelled || $text !== '';
                            ?>
                                <td class="px-4 py-3 <?= $c->numeric() ? 'text-right tabular-nums whitespace-nowrap' : '' ?>"><?= e($text) ?></td>
                            <?php endforeach ?>
                        </tr>
                    </tfoot>
                <?php endif ?>
            </table>
        </div>
    </div>
    <div class="mt-4"><?= $this->component('pagination', ['page' => $pager['page'], 'pages' => $pager['pages'], 'total' => $pager['total'], 'perPage' => $pager['per_page'], 'route' => 'staff.reports.show', 'query' => ['key' => $report->key()] + $q + $sheetQ + ($sort !== '' ? ['sort' => $sort, 'dir' => $dir] : [])]) ?></div>
<?php endif ?>

<?php if ($result->notes !== []): ?>
    <div class="mt-5 space-y-1 text-sm text-muted">
        <?php foreach ($result->notes as $n): ?><p class="flex gap-2"><?= icon('info', 'mt-0.5 size-4 shrink-0') ?><span><?= e($n) ?></span></p><?php endforeach ?>
    </div>
<?php endif ?>
