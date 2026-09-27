<?php
/**
 * Bookings list (read-only in batch 3).
 *
 * @var App\Core\Template $this
 * @var array{q: string, status: string} $filters
 * @var list<array<string, mixed>> $bookings
 * @var array<string, int> $counts
 */
use App\Enums\BookingStatus;

$this->layout('layouts/staff', ['title' => 'Bookings', 'subtitle' => 'Online requests and front-desk bookings.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Bookings']]]);
?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.explorer')) ?>" class="btn btn-brand"><?= icon('map', 'size-4') ?>Book on the map</a>
<?php $this->stop() ?>
<div class="mb-5 flex flex-wrap gap-2">
    <a href="<?= e(url('staff.bookings.index', array_filter(['q' => $filters['q']]))) ?>" class="chip <?= $filters['status'] === '' ? 'chip-active' : '' ?>">All <span class="opacity-70"><?= array_sum($counts) ?></span></a>
    <?php foreach (BookingStatus::cases() as $st): ?>
        <a href="<?= e(url('staff.bookings.index', array_filter(['q' => $filters['q'], 'status' => $st->value]))) ?>" class="chip <?= $filters['status'] === $st->value ? 'chip-active' : '' ?>"><?= e($st->label()) ?> <span class="opacity-70"><?= (int) ($counts[$st->value] ?? 0) ?></span></a>
    <?php endforeach ?>
</div>
<form method="get" class="mb-5 flex gap-2">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif ?>
    <input name="q" value="<?= e($filters['q']) ?>" class="input max-w-md" placeholder="Booking no., visitor name or Unique ID">
    <button class="btn btn-outline"><?= icon('search', 'size-4') ?>Search</button>
</form>
<?php if ($bookings === []): ?>
    <?= $this->component('empty', ['icon' => 'calendar-check', 'title' => 'No bookings found', 'text' => 'Online requests and reception bookings appear here.']) ?>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Booking</th><th>Visitor</th><th>Space</th><th>Dates</th><th>Source</th><th class="text-right">Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($bookings as $b): $st = BookingStatus::from((string) $b['status']); ?>
                <tr>
                    <td><a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $b['booking_no']])) ?>"><?= e($b['booking_no']) ?></a><div class="text-xs text-muted"><?= e(format_date($b['created_at'], 'd M, g:i a')) ?></div></td>
                    <td><div class="font-semibold"><?= e($b['customer_name']) ?></div><div class="font-mono text-xs text-muted"><?= e($b['unique_id'] ?? '—') ?></div></td>
                    <td><div class="font-semibold"><?= e($b['category_name']) ?></div><div class="max-w-56 truncate text-xs text-muted"><?= e((string) $b['seat_codes']) ?></div></td>
                    <td class="text-sm"><?= $b['start_time'] ? e(format_date($b['start_date'], 'd M') . ' · ' . substr((string) $b['start_time'], 0, 5) . '–' . substr((string) $b['end_time'], 0, 5)) : e(format_date($b['start_date'], 'd M') . ' → ' . format_date($b['end_date'], 'd M Y')) ?></td>
                    <td><?= $this->component('badge', ['label' => App\Enums\BookingSource::from((string) $b['source'])->label(), 'tone' => App\Enums\BookingSource::from((string) $b['source'])->tone()]) ?></td>
                    <td class="text-right font-semibold tabular-nums"><?= e(money($b['grand_total'])) ?></td>
                    <td><?= $this->component('badge', ['label' => $st->label(), 'tone' => $st->tone(), 'dot' => true]) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
