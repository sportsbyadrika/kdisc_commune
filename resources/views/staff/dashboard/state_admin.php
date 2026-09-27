<?php
/**
 * State Admin dashboard (read-only, spec 6.5) — DashboardService::stateAdmin().
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $dash
 */
$k = $dash['kpi'];
$pct = static fn (float $v, int $d = 1): string => number_format($v * 100, $d) . '%';
$reportRange = in_array($dash['preset'], ['month', 'last-month', '3m', '6m', 'fy', 'last-fy'], true) ? $dash['preset'] : 'month';
?>
<div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Dashboard period">
        <div class="flex w-max gap-1.5">
            <?php foreach ($dash['periods'] as $key => $label): $on = $key === $dash['preset']; ?>
                <a href="<?= e(url('staff.dashboard', ['range' => $key])) ?>" class="chip !py-1.5 text-xs <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
            <?php endforeach ?>
        </div>
    </nav>
    <p class="text-sm text-muted"><?= icon('calendar-range', 'mr-1 inline size-4 align-[-3px]') ?><?= e(format_date($dash['from'], 'd M Y')) ?> – <?= e(format_date($dash['to'], 'd M Y')) ?> · <a class="font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.reports.index')) ?>">All reports</a></p>
</div>

<div class="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:gap-4 lg:grid-cols-3 2xl:grid-cols-6 [&>*]:min-w-0">
    <?= $this->component('stat', ['label' => 'Revenue this month', 'value' => money($k['revenue_mtd']), 'icon' => 'trending-up', 'tone' => 'brand', 'hint' => 'net taxable invoiced, MTD']) ?>
    <?= $this->component('stat', ['label' => 'Revenue FY ' . $dash['fy'], 'value' => money($k['revenue_fytd']), 'icon' => 'indian-rupee', 'tone' => 'brand', 'hint' => 'net taxable, year to date']) ?>
    <?= $this->component('stat', ['label' => 'Collected', 'value' => money($k['collected']['total']), 'icon' => 'wallet', 'tone' => 'success', 'hint' => money($k['collected']['verified']) . ' verified · selected period']) ?>
    <?= $this->component('stat', ['label' => 'Dues outstanding', 'value' => money($k['dues']['due_now']), 'icon' => 'hourglass', 'tone' => $k['dues']['due_now'] > 0 ? 'warning' : 'success', 'hint' => $k['dues']['customers'] . ' visitor' . ($k['dues']['customers'] === 1 ? '' : 's') . ' · due now', 'href' => url('staff.reports.show', ['key' => 'dues-ageing'])]) ?>
    <?= $this->component('stat', ['label' => 'Active bookings', 'value' => $k['active_bookings'], 'icon' => 'calendar-check', 'tone' => 'info', 'hint' => $k['active_seats'] . ' seats confirmed or in use']) ?>
    <?= $this->component('stat', ['label' => 'Occupancy today', 'value' => $pct($k['occupancy_today']), 'icon' => 'armchair', 'tone' => 'accent', 'hint' => $pct($k['occupancy_period']) . ' over the period']) ?>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
    <section class="card card-body xl:col-span-2">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 class="text-lg font-bold">Revenue and collections</h2>
                <p class="text-sm text-muted">Last 12 months · net taxable invoiced (after credit notes) vs money received</p>
            </div>
            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.reports.show', ['key' => 'revenue', 'range' => 'fy'])) ?>">Revenue report</a>
        </div>
        <div class="mt-4 h-72"><canvas data-dash-chart="<?= e(json_encode(['kind' => 'trend-money', 'labels' => $dash['trend']['labels'], 'a' => $dash['trend']['revenue'], 'b' => $dash['trend']['collected'], 'labelA' => 'Net revenue invoiced', 'labelB' => 'Collected'])) ?>" role="img" aria-label="Monthly net revenue invoiced and collections, last 12 months"></canvas></div>
    </section>

    <section class="card card-body">
        <h2 class="text-lg font-bold">Payment status</h2>
        <p class="text-sm text-muted">Payments dated in the period</p>
        <p class="mt-4 font-display text-3xl font-extrabold tabular-nums"><?= e(money($dash['payments']['received'])) ?></p>
        <p class="text-sm text-muted">received (logged + verified)</p>
        <ul class="mt-5 divide-y divide-line">
            <?php foreach ($dash['payments']['items'] as $p): ?>
                <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                    <span class="inline-flex min-w-0 items-center gap-2"><?= $this->component('badge', ['label' => (string) $p['count'], 'tone' => $p['tone'], 'icon' => $p['icon']]) ?><span class="truncate"><?= e($p['label']) ?></span></span>
                    <span class="font-semibold tabular-nums"><?= e(money($p['amount'])) ?></span>
                </li>
            <?php endforeach ?>
            <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span class="inline-flex min-w-0 items-center gap-2"><?= $this->component('badge', ['label' => (string) $dash['payments']['awaiting']['count'], 'tone' => 'danger', 'icon' => 'wallet']) ?><span class="truncate">Approved, awaiting first payment</span></span>
                <span class="font-semibold tabular-nums"><?= e(money($dash['payments']['awaiting']['amount'])) ?></span>
            </li>
        </ul>
    </section>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
    <section class="card card-body xl:col-span-2">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 class="text-lg font-bold">Occupancy trend</h2>
                <p class="text-sm text-muted">Last 12 months · share of seat-days occupied by committed bookings</p>
            </div>
            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.reports.show', ['key' => 'occupancy', 'range' => $reportRange, 'group' => 'day'])) ?>">Occupancy report</a>
        </div>
        <div class="mt-4 h-64"><canvas data-dash-chart="<?= e(json_encode(['kind' => 'line-pct', 'label' => 'Occupancy', 'labels' => $dash['trend']['labels'], 'values' => $dash['trend']['occupancy']])) ?>" role="img" aria-label="Monthly occupancy percentage, last 12 months"></canvas></div>
    </section>

    <section class="card card-body">
        <h2 class="text-lg font-bold">Seats by space type</h2>
        <p class="text-sm text-muted">Today · occupied vs free</p>
        <div class="mt-5 space-y-4">
            <?php foreach ($dash['seatsByCategory'] as $c):
                $occW = $c['seats'] > 0 ? round($c['occupied'] / $c['seats'] * 100, 1) : 0;
                $blkW = $c['seats'] > 0 ? round($c['blocked'] / $c['seats'] * 100, 1) : 0; ?>
                <div>
                    <div class="flex items-baseline justify-between gap-2 text-sm">
                        <span class="font-semibold"><?= e($c['label']) ?></span>
                        <span class="font-semibold tabular-nums"><?= e(number_format($c['pct'] * 100, 0)) ?>%</span>
                    </div>
                    <div class="meter mt-2" role="img" aria-label="<?= e(sprintf('%s: %s%% occupied', $c['label'], $occW)) ?>">
                        <span class="bg-chart-1" style="width: <?= e((string) $occW) ?>%"></span>
                        <?php if ($blkW > 0): ?><span class="bg-seat-blocked" style="width: <?= e((string) $blkW) ?>%"></span><?php endif ?>
                    </div>
                    <p class="mt-1 text-xs text-muted"><strong class="tabular-nums text-ink"><?= e(number_format($c['occupied'], $c['occupied'] == floor($c['occupied']) ? 0 : 1)) ?></strong> occupied · <strong class="tabular-nums text-ink"><?= e(number_format($c['free'], $c['free'] == floor($c['free']) ? 0 : 1)) ?></strong> free of <?= (int) $c['seats'] ?><?= $c['blocked'] > 0 ? ' · ' . (int) $c['blocked'] . ' blocked' : '' ?></p>
                </div>
            <?php endforeach ?>
        </div>
        <div class="mt-5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
            <span class="inline-flex items-center gap-1.5"><span class="hm-swatch bg-chart-1"></span>Occupied</span>
            <span class="inline-flex items-center gap-1.5"><span class="hm-swatch bg-surface-2"></span>Free</span>
            <span class="inline-flex items-center gap-1.5"><span class="hm-swatch bg-seat-blocked"></span>Blocked</span>
        </div>
    </section>
</div>

<section class="card card-body mt-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <h2 class="text-lg font-bold">Occupancy heat-map</h2>
            <p class="text-sm text-muted">Each seat, cabin and room shaded by the share of its seat-days occupied, <?= e(format_date($dash['from'], 'd M')) ?> – <?= e(format_date($dash['to'], 'd M Y')) ?></p>
        </div>
    </div>
    <div class="mt-5 grid gap-6 xl:grid-cols-2 [&>*]:min-w-0">
        <?php foreach ($dash['heatmaps'] as $map): ?><?= $this->partial('partials/report/heatmap', ['map' => $map]) ?><?php endforeach ?>
    </div>
    <div class="mt-5"><?= $this->partial('partials/report/heat-legend', ['tableUrl' => url('staff.reports.show', ['key' => 'occupancy', 'range' => $reportRange, 'group' => 'unit'])]) ?></div>
</section>

<section class="card mt-6 overflow-hidden">
    <div class="card-body !pb-3">
        <h2 class="text-lg font-bold">By space type</h2>
        <p class="text-sm text-muted">Selected period · occupancy, bookings overlapping it and net revenue invoiced in it</p>
    </div>
    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th scope="col">Space type</th><th scope="col" class="!text-right">Units</th><th scope="col" class="!text-right">Seats</th><th scope="col">Occupancy</th><th scope="col" class="!text-right">Bookings</th><th scope="col" class="!text-right">Net revenue</th></tr></thead>
            <tbody class="divide-y divide-line bg-white">
            <?php foreach ($dash['categories'] as $c): ?>
                <tr>
                    <td class="font-semibold"><span class="mr-2 inline-block size-2.5 rounded-full" style="background: <?= e($c['colour']) ?>"></span><?= e($c['category']) ?></td>
                    <td class="text-right tabular-nums"><?= (int) $c['units'] ?></td>
                    <td class="text-right tabular-nums"><?= (int) $c['seats'] ?></td>
                    <td class="min-w-48"><div class="flex items-center gap-3"><div class="meter w-full max-w-40"><span class="bg-chart-1" style="width: <?= e((string) round($c['pct'] * 100, 1)) ?>%"></span></div><span class="w-14 text-right text-sm font-semibold tabular-nums"><?= e($pct((float) $c['pct'])) ?></span></div></td>
                    <td class="text-right tabular-nums"><?= (int) $c['bookings'] ?></td>
                    <td class="text-right tabular-nums"><?= e(money($c['revenue'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
