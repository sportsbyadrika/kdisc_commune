<?php
/**
 * Bookings console (spec 6.2 / 6.3): tabs Requests / Awaiting payment / Upcoming / Active / Renewals due /
 * Completed / Cancelled / All, filters (category, floor, dates, source, customer), pagination.
 *
 * @var App\Core\Template $this
 * @var string $tab
 * @var array<string, string> $filters
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, int> $counts
 * @var list<array<string, mixed>> $categories
 * @var list<array<string, mixed>> $floors
 * @var string $today
 */
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Services\Bookings\BookingDirectory;

$this->layout('layouts/staff', ['title' => 'Bookings', 'subtitle' => 'Requests, payments, arrivals and renewals — every booking in one place.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Bookings']]]);
$query = array_filter($filters + ['tab' => $tab], static fn ($v) => $v !== '' && $v !== null);
unset($query['within']);
if ($tab === 'renewals') {
    $query['within'] = $filters['within'];
}
$active = array_filter(array_intersect_key($filters, array_flip(['q', 'category', 'floor', 'source', 'from', 'to'])), static fn ($v) => $v !== '');
$hint = [
    'requests' => 'Online requests waiting for the Centre Manager. Approving needs verified KYC.',
    'payment' => 'Approved — waiting for the advance / deposit. They expire after the pay-by date.',
    'upcoming' => 'Paid and confirmed — seats allotted, not started yet.',
    'active' => 'Visitors currently on their tenure.',
    'renewals' => 'Confirmed or active bookings ending soon, without a follow-on booking yet.',
    'completed' => 'Finished tenures.',
    'cancelled' => 'Cancelled, expired or rejected.',
    'all' => 'Every booking.',
][$tab];
?>
<?php $this->start('actions') ?>
<?= $this->partial('partials/report/export-buttons', ['key' => 'bookings-list', 'query' => ['tab' => $tab] + $filters]) ?>
<?php if (staff_can('checkins.manage')): ?><a href="<?= e(url('staff.checkins.index')) ?>" class="btn btn-outline"><?= icon('scan-line', 'size-4') ?><span class="hidden sm:inline">Check-in desk</span></a><?php endif ?>
<?php if (staff_can('space.explore')): ?><a href="<?= e(url('staff.explorer')) ?>" class="btn btn-brand"><?= icon('map', 'size-4') ?>Book on the map</a><?php endif ?>
<?php $this->stop() ?>

<nav class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Booking tabs">
    <div class="flex w-max gap-2 pb-1 xl:w-auto xl:flex-wrap">
        <?php foreach (BookingDirectory::TABS as $key => [$label, $ic]): $on = $key === $tab; ?>
            <a href="<?= e(url('staff.bookings.index', ['tab' => $key] + array_diff_key($query, ['tab' => 1, 'page' => 1, 'within' => 1]))) ?>"
               class="chip <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>>
                <?= icon($ic, 'size-4') ?><?= e($label) ?>
                <span class="rounded-full px-1.5 text-xs font-bold <?= $on ? 'bg-white/20' : ($counts[$key] > 0 && in_array($key, ['requests', 'payment', 'renewals'], true) ? 'bg-accent-500 text-white' : 'bg-surface-2 text-muted') ?>"><?= (int) $counts[$key] ?></span>
            </a>
        <?php endforeach ?>
    </div>
</nav>

<form method="get" class="card mb-5 p-3 sm:p-4" x-data="{ more: <?= count(array_diff_key($active, ['q' => 1])) > 0 ? 'true' : 'false' ?> }">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="flex flex-col gap-2 sm:flex-row">
        <label class="flex min-w-0 flex-1 items-center gap-2 rounded-2xl bg-surface px-3 ring-1 ring-line focus-within:bg-white focus-within:ring-2 focus-within:ring-brand-600/30">
            <?= icon('search', 'size-4 shrink-0 text-muted') ?><span class="sr-only">Search</span>
            <input name="q" value="<?= e($filters['q']) ?>" class="w-full border-0 bg-transparent py-2.5 text-sm focus:ring-0 focus:outline-none" placeholder="Booking no., visitor name, Unique ID, mobile or email">
        </label>
        <div class="flex gap-2">
            <button type="button" class="btn btn-outline flex-1 sm:flex-none" @click="more = !more" :aria-expanded="more.toString()"><?= icon('filter', 'size-4') ?>Filters<?php if (count(array_diff_key($active, ['q' => 1])) > 0): ?><span class="rounded-full bg-brand-600 px-1.5 text-xs text-white"><?= count(array_diff_key($active, ['q' => 1])) ?></span><?php endif ?></button>
            <button class="btn btn-brand flex-1 sm:flex-none">Search</button>
        </div>
    </div>
    <div x-show="more" x-cloak class="mt-3 grid grid-cols-1 gap-3 border-t border-line pt-3 sm:grid-cols-2 lg:grid-cols-5">
        <?= $this->component('select', ['name' => 'category', 'label' => 'Space type', 'value' => $filters['category'], 'placeholder' => 'All types', 'options' => array_column($categories, 'name', 'code')]) ?>
        <?= $this->component('select', ['name' => 'floor', 'label' => 'Floor', 'value' => $filters['floor'], 'placeholder' => 'All floors', 'options' => array_column($floors, 'name', 'slug')]) ?>
        <?= $this->component('select', ['name' => 'source', 'label' => 'Source', 'value' => $filters['source'], 'placeholder' => 'Online & reception', 'options' => BookingSource::options()]) ?>
        <?= $this->component('input', ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => $filters['from']]) ?>
        <?= $this->component('input', ['name' => 'to', 'label' => 'To', 'type' => 'date', 'value' => $filters['to']]) ?>
        <?php if ($active !== []): ?><div class="sm:col-span-2 lg:col-span-5"><a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.index', ['tab' => $tab])) ?>">Clear all filters</a></div><?php endif ?>
    </div>
</form>

<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-muted"><?= e($hint) ?></p>
    <?php if ($tab === 'renewals'): ?>
        <div class="flex gap-1.5" role="group" aria-label="Ending within">
            <?php foreach ([7, 15, 30] as $d): ?>
                <a href="<?= e(url('staff.bookings.index', ['within' => $d] + $query)) ?>" class="chip !py-1 text-xs <?= (int) $filters['within'] === $d ? 'chip-active' : '' ?>"><?= $d ?> days</a>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>

<?php if ($result['rows'] === []): ?>
    <?= $this->component('empty', ['icon' => BookingDirectory::TABS[$tab][1], 'title' => 'Nothing here', 'text' => $active !== [] ? 'No bookings match these filters.' : $hint]) ?>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Booking</th><th>Visitor</th><th>Space</th><th>Dates</th><th class="hidden 2xl:table-cell">Source</th><th class="text-right">Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $b):
                $st = BookingStatus::from((string) $b['status']);
                $src = BookingSource::from((string) $b['source']);
                $daysLeft = (int) round((strtotime((string) $b['end_date']) - strtotime($today)) / 86400); ?>
                <tr>
                    <td class="whitespace-nowrap"><a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $b['booking_no']])) ?>"><?= e($b['booking_no']) ?></a>
                        <div class="text-xs text-muted"><?= e(format_date($b['created_at'], 'd M, g:i a')) ?><span class="2xl:hidden"> · <?= e($src->label()) ?></span></div></td>
                    <td><div class="max-w-48 truncate font-semibold"><?= e($b['customer_name']) ?></div>
                        <div class="flex items-center gap-1.5 font-mono text-xs text-muted"><?= e($b['unique_id'] ?? '—') ?><?php if ($b['kyc_status'] !== 'verified'): ?><span class="rounded bg-amber-100 px-1 font-sans text-[10px] font-bold text-amber-800 uppercase">KYC</span><?php endif ?></div></td>
                    <td><div class="font-semibold whitespace-nowrap"><?= e($b['category_name']) ?></div><div class="max-w-48 truncate text-xs text-muted"><?= e((string) $b['seat_codes']) ?><?= $b['floor_name'] ? ' · ' . e($b['floor_name']) : '' ?></div></td>
                    <td class="text-sm whitespace-nowrap"><?= $b['start_time'] ? e(format_date($b['start_date'], 'd M') . ' · ' . substr((string) $b['start_time'], 0, 5) . '–' . substr((string) $b['end_time'], 0, 5)) : e(format_date($b['start_date'], 'd M') . ' → ' . format_date($b['end_date'], 'd M Y')) ?>
                        <?php if ($tab === 'renewals' || ($st === BookingStatus::Active && $daysLeft <= 30)): ?><div class="text-xs font-bold <?= $daysLeft <= 7 ? 'text-accent-600' : 'text-amber-700' ?>"><?= $daysLeft <= 0 ? 'ends today' : 'ends in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') ?></div><?php endif ?>
                        <?php if ($st === BookingStatus::Approved && !empty($b['payment_due_by'])): ?><div class="text-xs font-bold <?= $b['payment_due_by'] < $today ? 'text-red-600' : 'text-muted' ?>">pay by <?= e(format_date($b['payment_due_by'], 'd M')) ?></div><?php endif ?></td>
                    <td class="hidden 2xl:table-cell"><?= $this->component('badge', ['label' => $src->label(), 'tone' => $src->tone()]) ?></td>
                    <td class="text-right font-semibold whitespace-nowrap tabular-nums"><?= e(money($b['grand_total'])) ?></td>
                    <td><?= $this->component('badge', ['label' => $st->label(), 'tone' => $st->tone(), 'dot' => true]) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <div class="mt-5"><?= $this->component('pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'], 'perPage' => $result['per_page'], 'route' => 'staff.bookings.index', 'query' => $query]) ?></div>
<?php endif ?>
