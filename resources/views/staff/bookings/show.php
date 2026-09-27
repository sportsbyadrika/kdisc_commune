<?php
/**
 * Booking detail (read-only in batch 3; batch 4 adds approve / reject / payments / check-in).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking
 * @var list<array<string, mixed>> $seats
 * @var list<array<string, mixed>> $facilities
 * @var list<array<string, mixed>> $timeline
 */
use App\Enums\BookingStatus;
use App\Enums\KycStatus;

$st = BookingStatus::from((string) $booking['status']);
$kyc = KycStatus::tryFrom((string) $booking['kyc_status']) ?? KycStatus::NotSubmitted;
$q = json_decode((string) ($booking['quote_json'] ?? ''), true) ?: null;
$this->layout('layouts/staff', ['title' => 'Booking ' . $booking['booking_no'], 'subtitle' => $booking['category_name'] . ' · ' . $booking['seat_codes'], 'breadcrumb' => [['Bookings', url('staff.bookings.index')], [(string) $booking['booking_no']]]]);
?>
<?php $this->start('actions') ?>
<?= $this->component('badge', ['label' => $st->label(), 'tone' => $st->tone(), 'dot' => true, 'class' => 'text-sm !px-3 !py-1']) ?>
<?php $this->stop() ?>
<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
    <div class="space-y-6">
        <section class="card card-body">
            <h2 class="text-lg font-bold">Visitor</h2>
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <span class="grid size-12 place-items-center rounded-full bg-brand-600 text-lg font-bold text-white"><?= e(mb_substr((string) $booking['customer_name'], 0, 1)) ?></span>
                <div class="min-w-0 flex-1">
                    <p class="font-bold"><?= e($booking['customer_name']) ?></p>
                    <p class="font-mono text-sm text-muted"><?= e($booking['unique_id'] ?? '—') ?> · <?= e(format_phone((string) $booking['customer_mobile'])) ?></p>
                </div>
                <?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
                <?php if ($booking['unique_id']): ?><a class="btn btn-outline btn-sm" href="<?= e(url('staff.visitors.show', ['ref' => $booking['unique_id']])) ?>">Profile</a><?php endif ?>
            </div>
            <?php if ($kyc !== KycStatus::Verified): ?><p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">KYC must be verified before this booking can be confirmed.</p><?php endif ?>
        </section>
        <section class="card card-body">
            <h2 class="text-lg font-bold">Seats &amp; period</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold text-muted uppercase">Dates</dt><dd class="mt-1 font-bold"><?= e(format_date($booking['start_date']) . ($booking['start_date'] !== $booking['end_date'] ? ' → ' . format_date($booking['end_date']) : '')) ?></dd></div>
                <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold text-muted uppercase">Time</dt><dd class="mt-1 font-bold"><?= $booking['start_time'] ? e(substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)) : 'Full day' ?></dd></div>
                <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold text-muted uppercase">Source</dt><dd class="mt-1 font-bold"><?= e(App\Enums\BookingSource::from((string) $booking['source'])->label()) ?></dd></div>
            </dl>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-line">
                <table class="table">
                    <thead><tr><th>Seat</th><th>Zone</th><th class="text-right">Rate</th><th class="text-right">Amount</th></tr></thead>
                    <tbody><?php foreach ($seats as $s): ?>
                        <tr><td class="font-bold"><?= e($s['kind'] === 'seat' ? $s['code'] : $s['label'] . ' (' . $s['code'] . ')') ?></td><td><?= e($s['floor_name'] . ' · ' . $s['zone_name']) ?></td><td class="text-right tabular-nums"><?= e(money($s['unit_price'])) ?></td><td class="text-right font-semibold tabular-nums"><?= e(money($s['amount'], 2)) ?></td></tr>
                    <?php endforeach ?></tbody>
                </table>
            </div>
            <?php if ($facilities !== []): ?>
                <div class="mt-4 flex flex-wrap gap-2"><?php foreach ($facilities as $f): ?><span class="rounded-full bg-surface px-3 py-1 text-sm font-semibold ring-1 ring-line"><?= e((string) $f['emoji']) ?> <?= e($f['name']) ?> × <?= (int) $f['qty'] ?> · <?= e(money($f['amount'], 2)) ?></span><?php endforeach ?></div>
            <?php endif ?>
            <?php if (!empty($booking['override_reason'])): ?><p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-800"><b>Override:</b> <?= e($booking['override_reason']) ?></p><?php endif ?>
            <?php if (!empty($booking['notes'])): ?><p class="mt-4 rounded-xl bg-surface p-3 text-sm"><?= e($booking['notes']) ?></p><?php endif ?>
        </section>
        <?php if ($q !== null): ?><section class="card card-body"><h2 class="mb-4 text-lg font-bold">Price</h2><?= $this->partial('partials/booking/price-summary', ['q' => $q]) ?></section><?php endif ?>
    </div>
    <aside class="card card-body lg:sticky lg:top-24">
        <h2 class="mb-5 text-lg font-bold">Status</h2>
        <?= $this->partial('partials/booking/timeline', ['timeline' => $timeline]) ?>
        <p class="mt-6 rounded-2xl bg-surface p-4 text-xs text-muted">Approve / reject, payment logging and check-in arrive with the bookings &amp; payments module.</p>
    </aside>
</div>
