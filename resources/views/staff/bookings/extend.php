<?php
/**
 * Extend / renew a booking (RenewalService): a linked follow-on booking from the day after the end date, same seats
 * (matched by seat_key) or a replacement where taken, re-quoted at the rate effective on the new start date.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking
 * @var array<string, mixed> $proposal
 * @var array<string, mixed>|null $quote
 * @var array<string, mixed>|null $existing
 * @var string $minEnd
 */
$no = (string) $booking['booking_no'];
$this->layout('layouts/staff', [
    'title' => 'Extend booking',
    'subtitle' => $no . ' · ' . $booking['customer_name'] . ' · ends ' . format_date((string) $booking['end_date']),
    'breadcrumb' => [['Bookings', url('staff.bookings.index')], [$no, url('staff.bookings.show', ['no' => $no])], ['Extend']],
]);
$taken = array_filter($proposal['seats'], static fn (array $s) => !$s['available']);
?>
<?php if ($existing !== null): ?>
    <?= $this->component('alert', ['tone' => 'warning', 'class' => 'mb-5', 'message' => 'This booking already has a follow-on booking: ' . $existing['booking_no'] . ' (' . $existing['status'] . '). Creating another one is allowed but usually not intended.']) ?>
<?php endif ?>
<form method="get" action="<?= e(url('staff.bookings.extend', ['no' => $no])) ?>" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
    <div class="min-w-0 space-y-6">
        <section class="card card-body">
            <h2 class="text-lg font-bold">New period</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div><p class="label">Starts</p><p class="input bg-surface font-bold"><?= e(format_date($proposal['from'], 'D, d M Y')) ?></p></div>
                <?= $this->component('input', ['name' => 'to', 'label' => 'Ends', 'type' => 'date', 'value' => $proposal['to'], 'required' => true, 'attrs' => ['min' => $minEnd]]) ?>
                <div class="flex items-end"><button class="btn btn-outline w-full"><?= icon('refresh-cw', 'size-4') ?>Update quote</button></div>
            </div>
            <p class="mt-3 text-xs text-muted">Starts the day after the current end date (or today if that has passed). Quoted at the rate effective on the new start date — rate changes since the original booking apply.</p>
        </section>
        <section class="card card-body">
            <h2 class="text-lg font-bold">Seats</h2>
            <p class="mt-1 text-sm text-muted">Matched to the current layout by seat identity. <?= $taken !== [] ? 'Pick a replacement for every seat that is taken for the new dates.' : 'All seats are free for the new dates.' ?></p>
            <ul class="mt-4 space-y-2">
                <?php foreach ($proposal['seats'] as $s): ?>
                    <li class="flex flex-wrap items-center gap-3 rounded-2xl p-3 ring-1 <?= $s['available'] ? 'ring-emerald-200 bg-emerald-50/60' : 'ring-amber-300 bg-amber-50' ?>">
                        <span class="font-display text-lg font-extrabold"><?= e($s['code']) ?></span>
                        <?= $this->component('badge', ['label' => $s['available'] ? 'Free' : ($s['status'] === 'removed' ? 'No longer in the layout' : ucfirst((string) $s['status']) . ' for these dates'), 'tone' => $s['available'] ? 'success' : 'warning']) ?>
                        <?php if (!$s['available']): ?>
                            <label class="ml-auto flex items-center gap-2 text-sm"><span class="font-semibold">Replace with</span>
                                <select name="replace[<?= (int) $s['seat_key'] ?>]" class="input !w-auto !py-2">
                                    <option value="">Choose a free seat…</option>
                                    <?php foreach ($s['alternatives'] as $alt): ?>
                                        <option value="<?= (int) $alt['id'] ?>" <?= (int) $s['replacement'] === $alt['id'] ? 'selected' : '' ?>><?= e($alt['code'] . ' · ' . $alt['floor'] . ' · ' . $alt['zone']) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </label>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
            <?php if ($taken !== []): ?><button class="btn btn-outline btn-sm mt-4"><?= icon('refresh-cw', 'size-4') ?>Apply replacements &amp; re-quote</button><?php endif ?>
        </section>
    </div>
    <aside class="card card-body lg:sticky lg:top-24">
        <h2 class="mb-4 text-lg font-bold">Extension quote</h2>
        <?php if ($quote !== null): ?>
            <?= $this->partial('partials/booking/price-summary', ['q' => $quote]) ?>
        <?php elseif ($proposal['error']): ?>
            <?= $this->component('alert', ['tone' => 'danger', 'message' => $proposal['error']]) ?>
        <?php else: ?>
            <p class="rounded-2xl bg-surface p-4 text-sm text-muted">Choose replacements for the taken seats to see the price.</p>
        <?php endif ?>
    </aside>
</form>
<?php if ($proposal['complete']): ?>
    <form method="post" action="<?= e(url('staff.bookings.extend.store', ['no' => $no])) ?>" class="card card-body mt-6 flex flex-col gap-4 sm:flex-row sm:items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="to" value="<?= e($proposal['to']) ?>">
        <?php foreach ($proposal['seats'] as $s): if ($s['replacement']): ?><input type="hidden" name="replace[<?= (int) $s['seat_key'] ?>]" value="<?= (int) $s['replacement'] ?>"><?php endif; endforeach ?>
        <?= $this->component('input', ['name' => 'notes', 'label' => 'Note (optional)', 'class' => 'flex-1', 'placeholder' => 'Extension of ' . $no]) ?>
        <button class="btn btn-brand btn-lg"><?= icon('calendar-check', 'size-5') ?>Create extension</button>
    </form>
    <p class="mt-2 text-xs text-muted">The extension is created as <b>Approved</b> and linked to <?= e($no) ?>; log its payment to confirm it. The visitor is emailed.</p>
<?php endif ?>
