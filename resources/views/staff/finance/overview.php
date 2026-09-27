<?php
/**
 * Finance overview body (FinanceOverview::build()) — used by /staff/finance and the Finance Admin dashboard.
 * The page must load assets/vendor/chart.umd.min.js and assets/js/finance.js in its head section.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $finance
 */
$f = $finance;
$m = $f['month'];
$y = $f['year'];
$pct = static fn (float $a, float $b) => $b > 0 ? min(100, (int) round($a / $b * 100)) : 0;
$money0 = static fn ($v) => money($v, fmod((float) $v, 1.0) ? 2 : 0);
$catTotal = array_sum(array_column($f['byCategory'], 'amount'));
$facTotal = array_sum(array_column($f['byFacility'], 'amount'));
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => 'Collected · ' . $m['label'], 'value' => $money0($m['collected']['total']), 'icon' => 'trending-up', 'tone' => 'success', 'hint' => $money0($m['due']) . ' fell due this month · ' . $money0($m['collected']['verified']) . ' verified']) ?>
    <?= $this->component('stat', ['label' => 'Payments to verify', 'value' => $f['verification']['pending'], 'icon' => 'hourglass', 'tone' => $f['verification']['pending'] > 0 ? 'warning' : 'success', 'hint' => $money0($f['verification']['pending_amount']) . ' logged' . ($f['verification']['queried'] ? ' · ' . $f['verification']['queried'] . ' queried' : ''), 'href' => route_exists('staff.payments.index') ? url('staff.payments.index') : null]) ?>
    <?= $this->component('stat', ['label' => 'Invoice queue', 'value' => $f['queue'], 'icon' => 'inbox', 'tone' => $f['queue'] > 0 ? 'accent' : 'brand', 'hint' => 'verified, not yet invoiced', 'href' => url('staff.invoices.index', ['tab' => 'queue'])]) ?>
    <?= $this->component('stat', ['label' => 'Deposits held', 'value' => $money0($f['depositsHeld']), 'icon' => 'piggy-bank', 'tone' => 'info', 'hint' => 'verified security deposits']) ?>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
    <section class="card card-body xl:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">Collections vs dues</h2>
                <p class="text-sm text-muted">FY <?= e($f['fy']) ?> by month — dues fall on the pay-by / rent due date; collections by payment date.</p>
            </div>
            <div class="text-right text-sm">
                <p class="font-display text-2xl font-extrabold tabular-nums"><?= e($money0($y['collected']['total'])) ?></p>
                <p class="text-muted">collected of <?= e($money0($y['due'])) ?> due so far</p>
            </div>
        </div>
        <div class="mt-4 h-64"><canvas data-chart="<?= e(json_encode(['kind' => 'monthly'] + $f['chart'])) ?>" role="img" aria-label="Monthly dues and collections"></canvas></div>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl bg-surface p-3"><p class="text-xs font-semibold text-muted">Verified by Finance (FY)</p><p class="mt-1 font-bold tabular-nums"><?= e($money0($y['collected']['verified'])) ?></p></div>
            <div class="rounded-2xl bg-surface p-3"><p class="text-xs font-semibold text-muted">Outstanding now</p><p class="mt-1 font-bold tabular-nums <?= $f['outstanding']['due_now'] > 0 ? 'text-red-700' : '' ?>"><?= e($money0($f['outstanding']['due_now'])) ?></p></div>
            <div class="rounded-2xl bg-surface p-3"><p class="text-xs font-semibold text-muted">Future balance (booked)</p><p class="mt-1 font-bold tabular-nums"><?= e($money0($f['outstanding']['balance'])) ?></p></div>
        </div>
    </section>

    <section class="card card-body">
        <h2 class="text-lg font-bold">GST collected</h2>
        <p class="text-sm text-muted">FY <?= e($f['fy']) ?> · invoices less credit notes</p>
        <p class="mt-4 font-display text-3xl font-extrabold tabular-nums"><?= e(money($f['gst']['total'], 2)) ?></p>
        <p class="text-sm text-muted">on taxable value <?= e(money($f['gst']['taxable'], 2)) ?> · <?= (int) $f['gst']['invoices'] ?> invoice<?= $f['gst']['invoices'] === 1 ? '' : 's' ?></p>
        <dl class="mt-5 divide-y divide-line rounded-2xl ring-1 ring-line">
            <?php foreach (['cgst' => 'CGST (Centre)', 'sgst' => 'SGST (Kerala)', 'igst' => 'IGST (inter-state)'] as $k => $label): ?>
                <div class="flex items-center justify-between px-4 py-3 text-sm"><dt class="text-muted"><?= e($label) ?></dt><dd class="font-bold tabular-nums"><?= e(money($f['gst'][$k], 2)) ?></dd></div>
            <?php endforeach ?>
        </dl>
        <a href="<?= e(url('staff.registers.index', ['type' => 'invoices', 'fy' => $f['fy']])) ?>" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline">Invoice register <?= icon('arrow-right', 'size-4') ?></a>
    </section>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
    <section class="card card-body">
        <h2 class="text-lg font-bold">Revenue by space type</h2>
        <p class="text-sm text-muted">Net taxable value invoiced, FY <?= e($f['fy']) ?></p>
        <?php if ($f['byCategory'] === []): ?>
            <p class="mt-6 rounded-2xl border-2 border-dashed border-line p-6 text-center text-sm text-muted">No invoices yet this year.</p>
        <?php else: ?>
            <div class="mt-4" style="height: <?= 40 + 44 * count($f['byCategory']) ?>px"><canvas data-chart="<?= e(json_encode(['kind' => 'hbar', 'label' => 'Revenue', 'labels' => array_column($f['byCategory'], 'label'), 'values' => array_column($f['byCategory'], 'amount')])) ?>" role="img" aria-label="Revenue by space type"></canvas></div>
            <p class="mt-2 text-right text-sm text-muted">Total <b class="text-ink"><?= e(money($catTotal)) ?></b></p>
        <?php endif ?>
    </section>
    <section class="card card-body">
        <h2 class="text-lg font-bold">Add-on revenue</h2>
        <p class="text-sm text-muted">By facility, FY <?= e($f['fy']) ?></p>
        <?php if ($f['byFacility'] === []): ?>
            <p class="mt-6 rounded-2xl border-2 border-dashed border-line p-6 text-center text-sm text-muted">No add-ons invoiced yet.</p>
        <?php else: ?>
            <ul class="mt-4 space-y-3">
                <?php foreach ($f['byFacility'] as $r): ?>
                    <li>
                        <div class="flex justify-between text-sm"><span class="font-semibold"><?= e($r['label']) ?></span><span class="tabular-nums"><?= e(money($r['amount'])) ?></span></div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full bg-brand-600" style="width: <?= $pct((float) $r['amount'], (float) max(array_column($f['byFacility'], 'amount'))) ?>%"></div></div>
                    </li>
                <?php endforeach ?>
            </ul>
            <p class="mt-3 text-right text-sm text-muted">Total <b class="text-ink"><?= e(money($facTotal)) ?></b></p>
        <?php endif ?>
    </section>
    <section class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5">
            <h2 class="text-lg font-bold">Recent invoices</h2>
            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.invoices.index', ['tab' => 'invoices'])) ?>">All</a>
        </div>
        <?php if ($f['recent'] === []): ?>
            <p class="m-5 rounded-2xl border-2 border-dashed border-line p-6 text-center text-sm text-muted">None issued yet.</p>
        <?php else: ?>
            <ul class="mt-2 divide-y divide-line">
                <?php foreach ($f['recent'] as $inv): ?>
                    <li><a href="<?= e(url('staff.invoices.show', ['id' => $inv['id']])) ?>" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-surface">
                        <span class="min-w-0"><span class="block font-mono text-xs font-bold text-brand-700"><?= e($inv['invoice_no']) ?></span><span class="block truncate text-sm font-semibold"><?= e($inv['customer_name']) ?></span></span>
                        <span class="text-right"><span class="block text-sm font-bold tabular-nums"><?= e(money($inv['total'], 2)) ?></span><span class="text-xs text-muted"><?= e(format_date((string) $inv['invoice_date'], 'd M')) ?><?= (float) $inv['igst'] > 0 ? ' · IGST' : '' ?></span></span>
                    </a></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</div>
