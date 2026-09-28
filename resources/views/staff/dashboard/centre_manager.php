<?php
/**
 * Centre Manager dashboard: the front-desk board + centre insights (DashboardService::centreManager()) — occupancy
 * heat-map, renewals pipeline, KYC queue, request → confirmation conversion and dues ageing.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $desk
 * @var array<string, int|float> $counts
 * @var int $totalSeats
 * @var array<string, mixed> $insights
 */
$in = $insights;
$conv = $in['conversion'];
$reportRange = $in['preset'];
$maxBucket = max(1.0, ...array_map(static fn (array $b) => (float) $b['amount'], $in['ageing']['buckets']));
$ageTone = ['current' => 'bg-heat-1', 'd0_30' => 'bg-heat-2', 'd31_60' => 'bg-heat-3', 'd61_90' => 'bg-heat-4', 'd90' => 'bg-heat-5'];
?>
<?= $this->partial('staff/dashboard/front-desk') ?>

<div class="mt-10 mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
        <h2 class="text-2xl font-extrabold">Centre insights</h2>
        <p class="text-sm text-muted">Occupancy for <?= e(format_date($in['from'], 'd M')) ?> – <?= e(format_date($in['to'], 'd M Y')) ?> · pipeline and money as of today</p>
    </div>
    <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Insights period">
        <div class="flex w-max gap-1.5 lg:w-auto lg:flex-wrap lg:justify-end">
            <?php foreach ($in['periods'] as $key => $label): $on = $key === $in['preset']; ?>
                <a href="<?= e(url('staff.dashboard', ['range' => $key])) ?>#insights" class="chip !py-1.5 text-xs <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
            <?php endforeach ?>
        </div>
    </nav>
</div>

<div id="insights" class="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:gap-4 xl:grid-cols-4 [&>*]:min-w-0">
    <?= $this->component('stat', ['label' => 'Occupancy', 'value' => number_format($in['occupancy'] * 100, 1) . '%', 'icon' => 'armchair', 'tone' => 'brand', 'hint' => 'seat-days in the period', 'href' => url('staff.reports.show', ['key' => 'occupancy', 'range' => $reportRange])]) ?>
    <?= $this->component('stat', ['label' => 'KYC queue', 'value' => $in['kyc']['pending'], 'icon' => 'shield-check', 'tone' => $in['kyc']['pending'] > 0 ? 'warning' : 'success', 'hint' => $in['kyc']['pending'] > 0 ? 'oldest waiting ' . $in['kyc']['oldest_days'] . ' day' . ($in['kyc']['oldest_days'] === 1 ? '' : 's') : 'nothing waiting', 'href' => url('staff.kyc.index')]) ?>
    <?= $this->component('stat', ['label' => 'Request → confirmed', 'value' => number_format($conv['rate'] * 100, 0) . '%', 'icon' => 'trending-up', 'tone' => 'success', 'hint' => $conv['confirmed'] . ' of ' . $conv['requested'] . ' bookings · last 90 days', 'href' => url('staff.reports.show', ['key' => 'bookings', 'range' => '3m'])]) ?>
    <?= $this->component('stat', ['label' => 'Dues due now', 'value' => money($in['ageing']['total']), 'icon' => 'hourglass', 'tone' => $in['ageing']['total'] > 0 ? 'danger' : 'success', 'hint' => $in['ageing']['bookings'] . ' booking' . ($in['ageing']['bookings'] === 1 ? '' : 's'), 'href' => url('staff.reports.show', ['key' => 'dues-ageing'])]) ?>
</div>

<section class="card card-body mt-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <h2 class="text-lg font-bold">Occupancy heat-map</h2>
            <p class="text-sm text-muted">Seats shaded by the share of their seat-days occupied in the period</p>
        </div>
    </div>
    <div class="mt-5 grid gap-6 xl:grid-cols-2 [&>*]:min-w-0">
        <?php foreach ($in['heatmaps'] as $map): ?><?= $this->partial('partials/report/heatmap', ['map' => $map]) ?><?php endforeach ?>
    </div>
    <div class="mt-5"><?= $this->partial('partials/report/heat-legend', ['tableUrl' => url('staff.reports.show', ['key' => 'occupancy', 'range' => $reportRange, 'group' => 'unit'])]) ?></div>
</section>

<div class="mt-6 grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
    <section class="card overflow-hidden xl:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
            <div>
                <h2 class="text-lg font-bold">Renewals pipeline</h2>
                <p class="text-sm text-muted">Bookings ending in the next 30 days</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?= $this->component('badge', ['label' => $in['renewals']['urgent'] . ' urgent (≤ 7 days)', 'tone' => $in['renewals']['urgent'] > 0 ? 'danger' : 'neutral']) ?>
                <?= $this->component('badge', ['label' => $in['renewals']['open'] . ' not renewed', 'tone' => 'warning']) ?>
                <?= $this->component('badge', ['label' => $in['renewals']['renewed'] . ' renewed', 'tone' => 'success']) ?>
            </div>
        </div>
        <?php if ($in['renewals']['rows'] === []): ?>
            <p class="px-5 py-8 text-sm text-muted">No bookings end in the next 30 days.</p>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-line">
                <?php foreach ($in['renewals']['rows'] as $r): ?>
                    <li><a class="flex items-center gap-3 px-5 py-3 transition hover:bg-surface" href="<?= e(url('staff.bookings.show', ['no' => $r['booking_no']])) ?>">
                        <span class="grid w-12 shrink-0 place-items-center rounded-xl py-1 text-center <?= $r['renewal_no'] === null && $r['days_left'] <= 7 ? 'bg-red-50 text-red-700' : 'bg-surface text-ink' ?>"><span class="font-display text-lg leading-none font-extrabold tabular-nums"><?= (int) $r['days_left'] ?></span><span class="text-[10px] font-semibold uppercase">days</span></span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold"><?= e($r['customer_name']) ?></span><span class="block truncate text-xs text-muted"><span class="font-mono"><?= e($r['booking_no']) ?></span> · <?= e($r['category']) ?><?= $r['seats'] ? ' · ' . e($r['seats']) : '' ?> · ends <?= e(format_date((string) $r['end_date'], 'd M')) ?></span></span>
                        <?= $this->component('badge', ['label' => $r['renewal_no'] !== null ? 'Renewed' : 'Not renewed', 'tone' => $r['renewal_no'] !== null ? 'success' : 'warning']) ?>
                    </a></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
        <div class="border-t border-line px-5 py-3 text-sm"><a class="font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.reports.show', ['key' => 'renewals', 'within' => '30'])) ?>">All <?= (int) $in['renewals']['total'] ?> in the renewals report →</a></div>
    </section>

    <div class="grid gap-6">
        <section class="card card-body">
            <h2 class="text-lg font-bold">Request → confirmation</h2>
            <p class="text-sm text-muted">Bookings created in the last 90 days · <?= (int) $conv['online'] ?> online, <?= (int) $conv['reception'] ?> at reception</p>
            <?php $steps = [['Requested', $conv['requested']], ['Approved', $conv['approved']], ['Confirmed', $conv['confirmed']]]; $ramp = ['bg-heat-5', 'bg-heat-4', 'bg-heat-3']; ?>
            <div class="mt-4 space-y-3">
                <?php foreach ($steps as $i => [$label, $n]): $w = $conv['requested'] > 0 ? round($n / $conv['requested'] * 100, 1) : 0; ?>
                    <div>
                        <div class="flex justify-between text-sm"><span class="font-semibold"><?= e($label) ?></span><span class="tabular-nums text-muted"><strong class="text-ink"><?= (int) $n ?></strong> · <?= e((string) round($w)) ?>%</span></div>
                        <div class="meter mt-1.5"><span class="<?= $ramp[$i] ?>" style="width: <?= e((string) $w) ?>%"></span></div>
                    </div>
                <?php endforeach ?>
            </div>
            <p class="mt-4 text-xs text-muted"><?= (int) $conv['declined'] ?> rejected or cancelled · <?= (int) $conv['open'] ?> still awaiting a decision</p>
        </section>

        <section class="card card-body">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-bold">Dues ageing</h2>
                <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.reports.show', ['key' => 'dues-ageing'])) ?>">Report</a>
            </div>
            <p class="text-sm text-muted">Money due now by days past its due date</p>
            <ul class="mt-4 space-y-3">
                <?php foreach ($in['ageing']['buckets'] as $b): ?>
                    <li>
                        <div class="flex justify-between gap-2 text-sm"><span class="font-semibold"><?= e($b['label']) ?></span><span class="tabular-nums"><strong><?= e(money($b['amount'])) ?></strong> <span class="text-muted">· <?= (int) $b['count'] ?></span></span></div>
                        <div class="meter mt-1.5"><span class="<?= $ageTone[$b['key']] ?? 'bg-heat-3' ?>" style="width: <?= e((string) round($b['amount'] / $maxBucket * 100, 1)) ?>%"></span></div>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    </div>
</div>
