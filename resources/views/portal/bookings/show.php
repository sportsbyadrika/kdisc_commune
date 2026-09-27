<?php
/**
 * Booking detail with status timeline (visitor portal).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var array<string, mixed> $booking
 * @var list<array<string, mixed>> $seats
 * @var list<array<string, mixed>> $facilities
 * @var list<array<string, mixed>> $timeline
 */
use App\Enums\BookingStatus;

$status = BookingStatus::from((string) $booking['status']);
$this->layout('layouts/portal', ['heading' => 'Booking ' . $booking['booking_no'], 'subheading' => $booking['category_name'] . ' · ' . $booking['seat_codes']]);
$q = json_decode((string) ($booking['quote_json'] ?? ''), true) ?: null;
?>
<div class="mb-6"><a href="<?= e(url('portal.bookings')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-muted hover:text-ink"><?= icon('arrow-left', 'size-4') ?>All bookings</a></div>
<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
    <div class="space-y-6">
        <section class="card card-body">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold">Details</h2>
                <?= $this->component('badge', ['label' => $status->label(), 'tone' => $status->tone(), 'dot' => true]) ?>
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <?php foreach ([
                    ['Space', (string) $booking['category_name']],
                    ['Floor', (string) $booking['floor_name']],
                    [$booking['start_time'] ? 'Date & time' : 'Dates', $booking['start_time']
                        ? format_date($booking['start_date'], 'D, d M Y') . ' · ' . substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)
                        : format_date($booking['start_date'], 'D, d M Y') . ' → ' . format_date($booking['end_date'], 'D, d M Y')],
                    ['Duration', ($q['duration']['label'] ?? '')],
                    ['Requested on', format_date($booking['created_at'], 'd M Y, g:i a')],
                    ['Source', App\Enums\BookingSource::from((string) $booking['source'])->label()],
                ] as [$k, $v]): ?>
                    <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold tracking-wide text-muted uppercase"><?= e($k) ?></dt><dd class="mt-1 font-bold"><?= e($v) ?></dd></div>
                <?php endforeach ?>
            </dl>
            <h3 class="mt-6 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Seats</h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($seats as $s): ?>
                    <span class="inline-flex items-center gap-2 rounded-xl bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-900 ring-1 ring-brand-200"><?= icon('armchair', 'size-4 text-brand-600') ?><?= e($s['kind'] === 'seat' ? $s['code'] : $s['label'] . ' · ' . $s['code']) ?><span class="text-xs font-medium text-brand-800/70"><?= e($s['zone_name']) ?></span></span>
                <?php endforeach ?>
            </div>
            <?php if ($facilities !== []): ?>
                <h3 class="mt-6 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Add-ons</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($facilities as $f): ?>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface px-3 py-1.5 text-sm font-semibold ring-1 ring-line"><?= e((string) $f['emoji']) ?> <?= e($f['name']) ?><?= (float) $f['qty'] > 1 ? ' × ' . (int) $f['qty'] : '' ?></span>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
            <?php if (!empty($booking['notes'])): ?><p class="mt-6 rounded-2xl bg-surface p-4 text-sm text-ink/80"><?= e($booking['notes']) ?></p><?php endif ?>
        </section>
        <?php if ($q !== null): ?>
            <section class="card card-body"><h2 class="mb-4 text-lg font-bold">Price</h2><?= $this->partial('partials/booking/price-summary', ['q' => $q]) ?></section>
        <?php endif ?>
    </div>
    <aside class="card card-body lg:sticky lg:top-24">
        <h2 class="mb-5 text-lg font-bold">Status</h2>
        <?= $this->partial('partials/booking/timeline', ['timeline' => $timeline]) ?>
        <?php if ($status === BookingStatus::Requested): ?>
            <p class="mt-6 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Your seats are reserved for you while the Centre Manager reviews the request. Questions? Call the front desk on <?= e((string) config('app.org.phone', '')) ?>.</p>
        <?php endif ?>
    </aside>
</div>
