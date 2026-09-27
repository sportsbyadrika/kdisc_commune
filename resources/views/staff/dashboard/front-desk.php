<?php
/**
 * Front-desk board (receptionist + Centre Manager): today's arrivals / departures, who is in, requests and
 * payments waiting, renewals due, top outstanding dues and a live occupancy mini-map per floor.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $desk  FrontDeskService::summary()
 * @var list<array<string, mixed>> $occupancy
 * @var App\Enums\StaffRole $role
 */
use App\Enums\BookingStatus;

$c = $desk['counts'];
$row = function (array $b, string $right = '') {
    $st = BookingStatus::from((string) $b['status']);
    return '<li><a class="flex items-center gap-3 px-4 py-3 transition hover:bg-surface" href="' . e(url('staff.bookings.show', ['no' => $b['booking_no']])) . '">'
        . '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold">' . e($b['customer_name']) . '</span>'
        . '<span class="block truncate text-xs text-muted"><span class="font-mono">' . e($b['booking_no']) . '</span> · ' . e($b['category_name']) . ($b['seat_codes'] ? ' · ' . e($b['seat_codes']) : '') . '</span></span>'
        . ($right !== '' ? $right : '<span class="badge badge-' . e($st->tone()) . '">' . e($st->label()) . '</span>')
        . '</a></li>';
};
?>
<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => 'Arrivals today', 'value' => $c['arrivals'], 'icon' => 'log-in', 'tone' => 'brand', 'hint' => 'Bookings starting today']) ?>
    <?= $this->component('stat', ['label' => 'Checked in now', 'value' => $c['checked_in'], 'icon' => 'users', 'tone' => 'success', 'hint' => $c['active'] . ' active bookings', 'href' => url('staff.checkins.index')]) ?>
    <?= $this->component('stat', ['label' => 'Online requests', 'value' => $c['requests'], 'icon' => 'inbox', 'tone' => 'warning', 'hint' => $role->can('bookings.approve') ? 'Awaiting your approval' : 'Awaiting the Centre Manager', 'href' => url('staff.bookings.index', ['tab' => 'requests'])]) ?>
    <?= $this->component('stat', ['label' => 'Awaiting payment', 'value' => $c['awaiting'], 'icon' => 'wallet', 'tone' => 'accent', 'hint' => 'Approved, not confirmed', 'href' => url('staff.bookings.index', ['tab' => 'payment'])]) ?>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
    <section class="card overflow-hidden xl:col-span-2">
        <div class="flex items-center justify-between gap-3 px-5 pt-5">
            <h2 class="text-lg font-bold">Today · <?= e(format_date($desk['today'], 'D, d M')) ?></h2>
            <?php if (staff_can('checkins.manage')): ?><a href="<?= e(url('staff.checkins.index')) ?>" class="btn btn-brand btn-sm"><?= icon('scan-line', 'size-4') ?>Check-in desk</a><?php endif ?>
        </div>
        <div class="mt-3 grid divide-y divide-line md:grid-cols-2 md:divide-x md:divide-y-0">
            <div>
                <p class="flex items-center gap-2 px-4 pt-2 pb-1 text-xs font-bold tracking-[0.14em] text-muted uppercase"><?= icon('log-in', 'size-3.5') ?>Arrivals <span class="rounded-full bg-surface-2 px-1.5"><?= count($desk['arrivals']) ?></span></p>
                <?php if ($desk['arrivals'] === [] && $desk['expected'] === []): ?><p class="px-4 pb-4 text-sm text-muted">No arrivals expected.</p><?php endif ?>
                <ul class="divide-y divide-line">
                    <?php foreach ($desk['arrivals'] as $b): ?>
                        <?= $row($b, (int) $b['checked_in'] > 0 ? '<span class="badge badge-success">In</span>' : '<span class="badge badge-' . e(BookingStatus::from((string) $b['status'])->tone()) . '">' . e(BookingStatus::from((string) $b['status'])->label()) . '</span>') ?>
                    <?php endforeach ?>
                    <?php foreach ($desk['expected'] as $b): ?>
                        <?= $row($b, '<span class="badge badge-neutral">Not in yet</span>') ?>
                    <?php endforeach ?>
                </ul>
            </div>
            <div>
                <p class="flex items-center gap-2 px-4 pt-2 pb-1 text-xs font-bold tracking-[0.14em] text-muted uppercase"><?= icon('log-out', 'size-3.5') ?>Departures <span class="rounded-full bg-surface-2 px-1.5"><?= count($desk['departures']) ?></span></p>
                <?php if ($desk['departures'] === []): ?><p class="px-4 pb-4 text-sm text-muted">Nobody leaves today.</p><?php endif ?>
                <ul class="divide-y divide-line">
                    <?php foreach ($desk['departures'] as $b): ?><?= $row($b, '<span class="text-xs font-semibold text-muted">last day</span>') ?><?php endforeach ?>
                </ul>
            </div>
        </div>
    </section>

    <section class="card card-body">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold">Renewals due</h2>
            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.index', ['tab' => 'renewals'])) ?>">All</a>
        </div>
        <div class="mt-4 grid grid-cols-3 gap-2">
            <?php foreach ($desk['renewals'] as $d => $n): ?>
                <a href="<?= e(url('staff.bookings.index', ['tab' => 'renewals', 'within' => $d])) ?>" class="rounded-2xl p-3 text-center ring-1 transition hover:shadow-md <?= $d === 7 && $n > 0 ? 'bg-accent-50 ring-accent-200' : 'bg-surface ring-line' ?>">
                    <span class="block font-display text-2xl font-extrabold tabular-nums"><?= (int) $n ?></span>
                    <span class="text-xs font-semibold text-muted">≤ <?= (int) $d ?> days</span>
                </a>
            <?php endforeach ?>
        </div>
        <h3 class="mt-6 text-sm font-bold">Outstanding dues</h3>
        <?php if ($desk['dues'] === []): ?>
            <p class="mt-2 text-sm text-muted">Nothing is due right now.</p>
        <?php else: ?>
            <ul class="mt-2 divide-y divide-line">
                <?php foreach ($desk['dues'] as $d): ?>
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="min-w-0"><span class="block truncate font-semibold"><?= e($d['name']) ?></span><span class="font-mono text-xs text-muted"><?= e($d['unique_id'] ?? '—') ?></span></span>
                        <span class="shrink-0 font-bold text-red-700 tabular-nums"><?= e(money($d['due_now'])) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-3">
    <section class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5">
            <h2 class="text-lg font-bold">Pending requests</h2>
            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.index', ['tab' => 'requests'])) ?>">Open</a>
        </div>
        <?php if ($desk['requests'] === []): ?><p class="px-5 py-4 text-sm text-muted">No online requests waiting.</p><?php endif ?>
        <ul class="mt-2 divide-y divide-line">
            <?php foreach ($desk['requests'] as $b): ?><?= $row($b, '<span class="text-xs text-muted">' . e(format_date((string) $b['start_date'], 'd M')) . '</span>') ?><?php endforeach ?>
        </ul>
    </section>
    <section class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5">
            <h2 class="text-lg font-bold">Awaiting payment</h2>
            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.index', ['tab' => 'payment'])) ?>">Open</a>
        </div>
        <?php if ($desk['awaiting'] === []): ?><p class="px-5 py-4 text-sm text-muted">No approved bookings waiting for payment.</p><?php endif ?>
        <ul class="mt-2 divide-y divide-line">
            <?php foreach ($desk['awaiting'] as $b): ?><?= $row($b, '<span class="text-xs font-semibold ' . (($b['payment_due_by'] ?? '') !== '' && $b['payment_due_by'] < $desk['today'] ? 'text-red-600' : 'text-muted') . '">' . ($b['payment_due_by'] ? 'by ' . e(format_date((string) $b['payment_due_by'], 'd M')) : '') . '</span>') ?><?php endforeach ?>
        </ul>
    </section>
    <section class="card card-body">
        <h2 class="text-lg font-bold">Quick actions</h2>
        <div class="mt-4 grid gap-2">
            <?php if (staff_can('visitors.register')): ?><a href="<?= e(url('staff.visitors.create')) ?>" class="flex items-center gap-3 rounded-2xl bg-surface p-3 font-semibold transition hover:bg-surface-2"><?= icon('user-plus', 'size-5 text-accent-600') ?>Register a walk-in</a><?php endif ?>
            <?php if (staff_can('space.explore')): ?><a href="<?= e(url('staff.explorer')) ?>" class="flex items-center gap-3 rounded-2xl bg-surface p-3 font-semibold transition hover:bg-surface-2"><?= icon('map', 'size-5 text-accent-600') ?>Book on the seat map</a><?php endif ?>
            <?php if (staff_can('checkins.manage')): ?><a href="<?= e(url('staff.checkins.index')) ?>" class="flex items-center gap-3 rounded-2xl bg-surface p-3 font-semibold transition hover:bg-surface-2"><?= icon('scan-line', 'size-5 text-accent-600') ?>Check-in / out</a><?php endif ?>
            <?php if ($role->can('kyc.verify')): ?><a href="<?= e(url('staff.kyc.index')) ?>" class="flex items-center justify-between gap-3 rounded-2xl bg-surface p-3 font-semibold transition hover:bg-surface-2"><span class="flex items-center gap-3"><?= icon('shield-check', 'size-5 text-accent-600') ?>KYC queue</span><span class="badge badge-info"><?= (int) $c['kyc_pending'] ?></span></a><?php endif ?>
        </div>
    </section>
</div>

<?php if ($occupancy !== []): ?>
    <section class="mt-6">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <h2 class="text-lg font-bold">Live occupancy · today</h2>
            <?php if (staff_can('space.explore')): ?><a href="<?= e(url('staff.explorer')) ?>" class="text-sm font-semibold text-brand-700 hover:underline">Open the Space Explorer</a><?php endif ?>
        </div>
        <div class="grid gap-6 xl:grid-cols-2">
            <?php foreach ($occupancy as $map): $s = $map['stats']; $pct = $s['units'] > 0 ? (int) round($s['occupied'] / $s['units'] * 100) : 0; ?>
                <div class="card card-body">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="font-bold"><?= e($map['floor']['name']) ?></p>
                        <p class="text-sm text-muted"><b class="text-ink"><?= (int) $s['occupied'] ?></b> of <?= (int) $s['units'] ?> units occupied · <?= $pct ?>% · <b class="text-emerald-700"><?= (int) $s['checked_in'] ?></b> in</p>
                    </div>
                    <?= $this->partial('partials/booking/minimap', ['map' => $map]) ?>
                </div>
            <?php endforeach ?>
        </div>
    </section>
<?php endif ?>
