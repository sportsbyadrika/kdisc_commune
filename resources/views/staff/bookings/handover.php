<?php
/**
 * Seat handover / transfer (Centre Manager): pick the booking's seat to move and a free seat of the same category
 * on the mini-map (reassign mode). History is kept in booking_seats; the price difference is shown, not billed.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking
 * @var list<array<string, mixed>> $current
 * @var list<array<string, mixed>> $maps
 * @var string $effective
 * @var string $quoteUrl
 */
$no = (string) $booking['booking_no'];
$this->layout('layouts/staff', [
    'title' => 'Hand over seats',
    'subtitle' => $no . ' · ' . $booking['customer_name'] . ' · ' . $booking['category_name'],
    'breadcrumb' => [['Bookings', url('staff.bookings.index')], [$no, url('staff.bookings.show', ['no' => $no])], ['Handover']],
]);
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/frontdesk.js')) ?>"></script>
<?php $this->stop() ?>
<?= $this->partial('partials/space/sprite', ['extra' => []]) ?>
<form method="post" action="<?= e(url('staff.bookings.handover.store', ['no' => $no])) ?>" x-data="handoverForm('<?= e($quoteUrl) ?>', <?= (int) ($current[0]['id'] ?? 0) ?>)"
      class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
    <?= csrf_field() ?>
    <div class="min-w-0 space-y-6">
        <?php foreach ($maps as $map): ?>
            <section class="card card-body">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-lg font-bold"><?= e($map['floor']['name']) ?></h2>
                    <span class="text-xs text-muted"><?= (int) $map['stats']['free'] ?> free units · <?= e(format_date($effective, 'd M') . ' → ' . format_date((string) $booking['end_date'], 'd M Y')) ?></span>
                </div>
                <?= $this->partial('partials/booking/minimap', ['map' => $map]) ?>
            </section>
        <?php endforeach ?>
    </div>
    <aside class="card card-body space-y-5 lg:sticky lg:top-24">
        <div>
            <p class="eyebrow">Step 1</p>
            <h2 class="text-lg font-bold">Seat to move</h2>
            <div class="mt-3 space-y-2">
                <?php foreach ($current as $s): ?>
                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl p-3 ring-1 ring-line has-[:checked]:bg-amber-50 has-[:checked]:ring-2 has-[:checked]:ring-amber-400">
                        <input type="radio" name="booking_seat_id" value="<?= (int) $s['id'] ?>" x-model.number="bookingSeat" class="size-4 text-amber-500">
                        <span class="min-w-0"><span class="block font-bold"><?= e($s['kind'] === 'seat' ? $s['code'] : $s['label'] . ' (' . $s['code'] . ')') ?></span><span class="block text-xs text-muted"><?= e($s['floor_name'] . ' · ' . $s['zone_name']) ?> · <?= e(money($s['unit_price'])) ?>/<?= e($booking['duration_unit']) ?></span></span>
                    </label>
                <?php endforeach ?>
            </div>
        </div>
        <div>
            <p class="eyebrow">Step 2</p>
            <h2 class="text-lg font-bold">New seat</h2>
            <input type="hidden" name="seat_id" :value="target ? target.id : ''">
            <div class="mt-3 rounded-2xl p-4 ring-1" :class="target ? 'bg-brand-50 ring-brand-200' : 'bg-surface ring-line'">
                <p x-show="!target" class="text-sm text-muted">Click a free <b><?= e(strtolower((string) $booking['category_name'])) ?></b> seat on the map.</p>
                <div x-show="target" x-cloak class="flex items-center justify-between gap-3">
                    <p class="font-display text-2xl font-extrabold text-brand-800" x-text="target ? (target.kind === 'seat' ? target.code : target.label) : ''"></p>
                    <button type="button" class="text-xs font-semibold text-muted hover:text-ink" @click="clear()">Clear</button>
                </div>
            </div>
            <div x-show="quote" x-cloak class="mt-3 rounded-2xl p-3 text-sm" :class="quote && quote.diff > 0 ? 'bg-amber-50 text-amber-900' : (quote && quote.diff < 0 ? 'bg-sky-50 text-sky-900' : 'bg-emerald-50 text-emerald-900')">
                <p class="font-bold" x-show="quote && quote.diff !== null && quote.diff !== undefined" x-text="quote ? (Math.abs(quote.diff) < 0.01 ? 'No price difference' : (quote.diff > 0 ? '+ ' : '− ') + money(quote.diff) + ' for the rest of the tenure') : ''"></p>
                <p class="mt-1 text-xs" x-text="quote ? quote.note : ''"></p>
                <span x-show="busy" class="text-xs">Calculating…</span>
            </div>
        </div>
        <?= $this->component('textarea', ['name' => 'reason', 'label' => 'Reason', 'rows' => 2, 'required' => true, 'placeholder' => 'e.g. Visitor asked for a window seat']) ?>
        <p class="text-xs text-muted">Effective <b class="text-ink"><?= e(format_date($effective, 'D, d M Y')) ?></b>. The old seat becomes free, an open check-in moves along, and the visitor is emailed. Price differences are not billed automatically.</p>
        <div class="flex gap-2">
            <a href="<?= e(url('staff.bookings.show', ['no' => $no])) ?>" class="btn btn-ghost flex-1">Back</a>
            <button class="btn btn-brand flex-1" :disabled="!target || !bookingSeat"><?= icon('move', 'size-4') ?>Hand over</button>
        </div>
    </aside>
</form>
