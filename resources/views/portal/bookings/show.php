<?php
/**
 * Booking detail (visitor portal): status timeline, dues + payment history (+ rent schedule for > 6 months),
 * "Cancel request" (requested / approved) and "Renew" (reopens the Space Explorer with the same seats).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var array<string, mixed> $booking
 * @var list<array<string, mixed>> $seats
 * @var list<array<string, mixed>> $facilities
 * @var list<array<string, mixed>> $timeline
 * @var array<string, mixed>|null $dues
 * @var list<array<string, mixed>> $payments
 * @var array<string, mixed>|null $renewal
 * @var array<string, mixed>|null $renewedFrom
 * @var bool $canRenew
 * @var string $today
 * @var array<string, list<array<string, mixed>>> $documents FinanceDocuments::forBooking()
 * @var string|null $allotmentUrl
 */
use App\Enums\BookingStatus;

$status = BookingStatus::from((string) $booking['status']);
$no = (string) $booking['booking_no'];
$this->layout('layouts/portal', ['heading' => 'Booking ' . $no, 'subheading' => $booking['category_name'] . ($booking['seat_codes'] ? ' · ' . $booking['seat_codes'] : '')]);
$q = json_decode((string) ($booking['quote_json'] ?? ''), true) ?: null;
$left = (int) round((strtotime((string) $booking['end_date']) - strtotime($today)) / 86400);
$startsIn = (int) round((strtotime((string) $booking['start_date']) - strtotime($today)) / 86400);
$m = static fn (float|int|string $v): string => money($v, fmod((float) $v, 1.0) !== 0.0 ? 2 : 0);
?>
<div x-data>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <a href="<?= e(url('portal.bookings')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-muted hover:text-ink"><?= icon('arrow-left', 'size-4') ?>All bookings</a>
    <div class="flex flex-wrap gap-2">
        <?php if ($canRenew && $renewal === null): ?>
            <a href="<?= e(url('portal.bookings.renew', ['no' => $no])) ?>" class="btn btn-primary"><?= icon('refresh-cw', 'size-4') ?>Renew</a>
        <?php endif ?>
        <?php if ($status->visitorCanCancel()): ?>
            <button type="button" class="btn btn-outline" @click="$dispatch('open-modal', 'cancel-request')"><?= icon('circle-x', 'size-4') ?><?= $status === BookingStatus::Requested ? 'Cancel request' : 'Cancel booking' ?></button>
        <?php endif ?>
    </div>
</div>

<?php if ($status === BookingStatus::Approved && $dues !== null && !$dues['confirmation_met']): ?>
    <div class="mb-6 flex flex-col gap-3 rounded-3xl bg-gradient-to-r from-brand-700 to-brand-900 p-5 text-white sm:flex-row sm:items-center sm:justify-between sm:p-6">
        <div>
            <p class="text-xs font-bold tracking-[0.16em] text-white/60 uppercase">Approved · next step</p>
            <p class="mt-1 text-xl font-extrabold !text-white">Pay <?= e($m(max(0, $dues['requirement']['total'] - $dues['paid']))) ?> at the front desk to confirm</p>
            <p class="mt-1 text-sm text-white/75"><?= e($dues['requirement']['summary']) ?><?= !empty($booking['payment_due_by']) ? ' Please pay by ' . e(format_date((string) $booking['payment_due_by'], 'D, d M Y')) . '.' : '' ?></p>
        </div>
        <span class="shrink-0 rounded-2xl bg-white/10 px-4 py-2 text-sm">Cash · UPI · NEFT · Card · Cheque</span>
    </div>
<?php elseif (in_array($status, [BookingStatus::Confirmed, BookingStatus::Active], true)): ?>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card card-body !p-4"><p class="text-xs font-bold text-muted uppercase"><?= $startsIn > 0 ? 'Starts in' : 'Days left' ?></p><p class="mt-1 font-display text-3xl font-extrabold tabular-nums <?= $startsIn <= 0 && $left <= 7 ? 'text-accent-600' : '' ?>"><?= $startsIn > 0 ? $startsIn : max(0, $left) ?></p></div>
        <div class="card card-body !p-4"><p class="text-xs font-bold text-muted uppercase">Ends</p><p class="mt-2 font-bold"><?= e(format_date((string) $booking['end_date'], 'D, d M Y')) ?></p></div>
        <?php if ($dues !== null): ?>
            <div class="card card-body !p-4"><p class="text-xs font-bold text-muted uppercase">Paid</p><p class="mt-2 font-bold text-emerald-700 tabular-nums"><?= e($m($dues['paid'])) ?></p></div>
            <div class="card card-body !p-4 <?= $dues['due_now'] > 0 ? 'ring-2 ring-red-200' : '' ?>"><p class="text-xs font-bold uppercase <?= $dues['due_now'] > 0 ? 'text-red-700' : 'text-muted' ?>">Due now</p><p class="mt-2 font-bold tabular-nums <?= $dues['due_now'] > 0 ? 'text-red-700' : '' ?>"><?= e($m($dues['due_now'])) ?></p></div>
        <?php endif ?>
    </div>
<?php endif ?>
<?php if ($renewal !== null): ?>
    <?= $this->component('alert', ['tone' => 'info', 'class' => 'mb-6', 'message' => 'Renewed as ' . $renewal['booking_no'] . ' (' . BookingStatus::from((string) $renewal['status'])->label() . ').']) ?>
<?php endif ?>

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
    <div class="min-w-0 space-y-6">
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
            <?php if (!empty($booking['exit_reason'])): ?><p class="mt-6 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Ended early (planned end <?= e(format_date((string) $booking['original_end_date'])) ?>).</p><?php endif ?>
            <?php if (!empty($booking['notes'])): ?><p class="mt-6 rounded-2xl bg-surface p-4 text-sm text-ink/80"><?= e($booking['notes']) ?></p><?php endif ?>
            <?php if ($renewedFrom !== null): ?><p class="mt-4 text-sm text-muted">Renewal of <a class="font-mono font-semibold text-brand-700 hover:underline" href="<?= e(url('portal.bookings.show', ['no' => $renewedFrom['booking_no']])) ?>"><?= e($renewedFrom['booking_no']) ?></a>.</p><?php endif ?>
        </section>

        <?php if ($dues !== null): ?>
            <section class="card card-body">
                <h2 class="mb-4 text-lg font-bold">Dues &amp; payments</h2>
                <?= $this->partial('partials/booking/dues', ['dues' => $dues, 'booking' => $booking]) ?>
                <h3 class="mt-6 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Payment history</h3>
                <?= $this->partial('partials/booking/payments', ['payments' => $payments]) ?>
            </section>
        <?php elseif ($payments !== []): ?>
            <section class="card card-body"><h2 class="mb-4 text-lg font-bold">Payments</h2><?= $this->partial('partials/booking/payments', ['payments' => $payments]) ?></section>
        <?php endif ?>

        <?php if ($allotmentUrl !== null || array_sum(array_map('count', $documents)) > 0 || $payments !== []): ?>
            <section class="card card-body">
                <div class="mb-4 flex items-center justify-between gap-3"><h2 class="text-lg font-bold">Documents</h2><a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('portal.invoices')) ?>">All invoices &amp; receipts</a></div>
                <?= $this->partial('partials/booking/documents', ['documents' => $documents, 'allotmentUrl' => $allotmentUrl, 'portal' => true]) ?>
            </section>
        <?php endif ?>

        <?php if ($q !== null): ?>
            <section class="card card-body"><h2 class="mb-4 text-lg font-bold">Price</h2><?= $this->partial('partials/booking/price-summary', ['q' => $q]) ?></section>
        <?php endif ?>
    </div>
    <aside class="card card-body lg:sticky lg:top-24">
        <h2 class="mb-5 text-lg font-bold">Status</h2>
        <?= $this->partial('partials/booking/timeline', ['timeline' => $timeline]) ?>
        <?php if ($status === BookingStatus::Requested): ?>
            <p class="mt-6 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">Your seats are reserved for you while the Centre Manager reviews the request. Questions? Call the front desk on <?= e((string) config('app.org.phone', '')) ?>.</p>
        <?php elseif ($status === BookingStatus::Confirmed): ?>
            <p class="mt-6 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900">Show the QR on your <a class="font-bold underline" href="<?= e(url('portal.dashboard')) ?>">ID card</a> at the front desk to check in.</p>
        <?php endif ?>
    </aside>
</div>

<?php if ($status->visitorCanCancel()): ?>
    <?php $this->begin('modal', ['id' => 'cancel-request', 'title' => $status === BookingStatus::Requested ? 'Cancel this request?' : 'Cancel this booking?', 'size' => 'sm']) ?>
        <form method="post" action="<?= e(url('portal.bookings.cancel', ['no' => $no])) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <p>Your seats <?= e((string) $booking['seat_codes']) ?> will be released for others. This cannot be undone.</p>
            <?= $this->component('textarea', ['name' => 'reason', 'label' => 'Reason (optional)', 'rows' => 2, 'placeholder' => 'e.g. Plans changed']) ?>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'cancel-request')">Keep it</button><button class="btn btn-danger">Cancel <?= $status === BookingStatus::Requested ? 'request' : 'booking' ?></button></div>
        </form>
    <?= $this->end() ?>
<?php endif ?>
</div>
