<?php
/**
 * Booking detail (staff): status timeline, customer card, seats + mini-map, add-ons, quote snapshot, payment rule
 * + dues + rent schedule, payments, check-ins, activity, and the actions allowed for this state and role.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking
 * @var array<string, mixed> $customer
 * @var list<array<string, mixed>> $seats       all booking_seats rows (incl. handed-over history)
 * @var list<array<string, mixed>> $current     seats covering today, with open check-in
 * @var list<array<string, mixed>> $facilities
 * @var list<array<string, mixed>> $timeline
 * @var list<array<string, mixed>> $activity
 * @var list<array<string, mixed>> $checkinHistory
 * @var array<string, mixed> $dues
 * @var list<array<string, mixed>> $payments
 * @var array<string, mixed> $outstanding
 * @var array<string, bool> $actions
 * @var array<string, mixed>|null $renewal
 * @var array<string, mixed>|null $renewedFrom
 * @var list<array<string, mixed>> $maps
 * @var array<string, string> $kinds
 * @var array<string, string> $modes
 * @var string $suggestedKind
 * @var string $today
 */
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\KycStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentRule;
use App\Services\Kyc\AadhaarVault;
use App\Services\Kyc\DocumentStore;

$st = BookingStatus::from((string) $booking['status']);
$kyc = KycStatus::tryFrom((string) $booking['kyc_status']) ?? KycStatus::NotSubmitted;
$q = json_decode((string) ($booking['quote_json'] ?? ''), true) ?: null;
$no = (string) $booking['booking_no'];
$hourly = $booking['start_time'] !== null;
$m = static fn (float|int|string $v): string => money($v, fmod((float) $v, 1.0) !== 0.0 ? 2 : 0);
$history = array_values(array_filter($seats, static fn (array $s) => $s['transferred_to_id'] !== null));
$currentSeats = array_values(array_filter($seats, static fn (array $s) => $s['transferred_to_id'] === null));
$openIn = array_values(array_filter($current, static fn (array $s) => $s['open_checkin_id'] !== null));
$daysLeft = (int) round((strtotime((string) $booking['end_date']) - strtotime($today)) / 86400);
// masked identity numbers for the customer card (never the full Aadhaar / PAN)
$ids = [];
if (($customer['aadhaar_last4'] ?? '') !== '') {
    $ids[] = ['Aadhaar', AadhaarVault::mask((string) $customer['aadhaar_last4'])];
} elseif (!empty($customer['passport_no'])) {
    $ids[] = ['Passport', mask_id((string) $customer['passport_no'])];
}
if (!empty($customer['pan'])) {
    $ids[] = ['PAN', mask_id((string) $customer['pan'])];
}
if (!empty($customer['gstin']) && count($ids) < 2) {
    $ids[] = ['GSTIN', mask_id((string) $customer['gstin'], 2, 4)];
}
$ids = array_slice($ids ?: [['ID', '—']], 0, 2);
$activityLabels = [
    'booking.create' => 'Booking created', 'booking.approved' => 'Approved', 'booking.rejected' => 'Rejected', 'booking.confirmed' => 'Confirmed',
    'booking.active' => 'Started', 'booking.completed' => 'Completed', 'booking.cancelled' => 'Cancelled', 'booking.early_exit' => 'Ended early',
    'booking.handover' => 'Seat handed over', 'booking.extend' => 'Extended', 'payment.log' => 'Payment logged', 'payment.void' => 'Payment voided',
    'payment.proof.view' => 'Payment proof viewed', 'checkin.in' => 'Checked in', 'checkin.out' => 'Checked out',
];
$refLabels = [];
foreach (PaymentMode::cases() as $pm) {
    $refLabels[$pm->value] = $pm->referenceLabel();
}
// quick-fill amounts for the payment modal
$quick = [];
if ($dues['rule'] === PaymentRule::Advance) {
    if ($dues['balance'] > 0) {
        $quick[] = [$dues['balance'], 'Full balance', 'advance'];
    }
} else {
    $depLeft = round($dues['deposit']['due'] - $dues['deposit']['paid'], 2);
    if ($depLeft > 0) {
        $quick[] = [$depLeft, 'Security deposit', 'deposit'];
    }
    foreach ($dues['schedule'] as $p) {
        if ($p['left'] > 0) {
            $quick[] = [$p['left'], 'Rent · period ' . $p['period_no'], 'rent'];
            break;
        }
    }
}
$this->layout('layouts/staff', [
    'title' => 'Booking ' . $no,
    'subtitle' => $booking['category_name'] . ($booking['seat_codes'] ? ' · ' . $booking['seat_codes'] : '') . ' · ' . $booking['customer_name'],
    'breadcrumb' => [['Bookings', url('staff.bookings.index')], [$no]],
]);
$errorsOpen = errors('amount') !== null || errors('reference_no') !== null || errors('paid_on') !== null || errors('kind') !== null || errors('mode') !== null || errors('proof') !== null;
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/frontdesk.js')) ?>"></script>
<?php $this->stop() ?>
<?php $this->start('actions') ?>
<?= $this->component('badge', ['label' => $st->label(), 'tone' => $st->tone(), 'dot' => true, 'class' => 'text-sm !px-3 !py-1']) ?>
<?php $this->stop() ?>
<?= $this->partial('partials/space/sprite', ['extra' => []]) ?>

<?php if ($errorsOpen): ?><div x-data x-init="$nextTick(() => $dispatch('open-modal', 'log-payment'))"></div><?php endif ?>

<!-- Next step / actions -->
<section class="card mb-6 overflow-hidden">
    <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid size-11 shrink-0 place-items-center rounded-2xl <?= ['warning' => 'bg-amber-50 text-amber-700', 'info' => 'bg-sky-50 text-sky-700', 'brand' => 'bg-brand-50 text-brand-700', 'success' => 'bg-emerald-50 text-emerald-700', 'danger' => 'bg-red-50 text-red-700'][$st->tone()] ?? 'bg-surface text-muted' ?>">
                <?= icon(match ($st) { BookingStatus::Requested => 'inbox', BookingStatus::Approved => 'wallet', BookingStatus::Confirmed => 'calendar-clock', BookingStatus::Active => 'armchair', BookingStatus::Completed => 'circle-check', default => 'circle-x' }, 'size-5') ?>
            </span>
            <div class="min-w-0">
                <p class="font-bold"><?= e(match ($st) {
                    BookingStatus::Requested => $kyc === KycStatus::Verified ? 'Online request — review and approve or reject.' : 'Online request — verify the visitor’s KYC before approving.',
                    BookingStatus::Approved => $dues['confirmation_met'] ? 'Payment complete — confirm once KYC is verified.' : 'Approved — waiting for the ' . strtolower((string) $dues['requirement']['label']) . ' of ' . $m(max(0, $dues['requirement']['total'] - $dues['paid'])) . ' more.',
                    BookingStatus::Confirmed => (string) $booking['start_date'] <= $today ? 'Confirmed — check the visitor in to start the booking.' : 'Confirmed — starts ' . format_date((string) $booking['start_date'], 'D, d M') . '.',
                    BookingStatus::Active => $daysLeft <= 0 ? 'Active — last day today.' : 'Active — ends in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' (' . format_date((string) $booking['end_date'], 'd M Y') . ').',
                    BookingStatus::Completed => 'Completed' . (!empty($booking['original_end_date']) ? ' early (planned end ' . format_date((string) $booking['original_end_date']) . ').' : '.'),
                    BookingStatus::Cancelled => 'Cancelled' . (!empty($booking['cancel_reason']) ? ': ' . $booking['cancel_reason'] : '.'),
                    BookingStatus::Rejected => 'Rejected: ' . ($booking['rejected_reason'] ?? ''),
                }) ?></p>
                <?php if (session()->getFlash('kyc_link')): ?><a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.kyc.show', ['id' => $booking['customer_id']])) ?>">Open KYC review →</a><?php endif ?>
                <?php if ($openIn !== []): ?><p class="text-sm text-emerald-700"><?= icon('circle-check', 'size-3.5 inline') ?> Checked in: <?= e(implode(', ', array_column($openIn, 'code'))) ?></p><?php endif ?>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if ($actions['approve']): ?>
                <?php if ($kyc === KycStatus::Verified): ?>
                    <form method="post" action="<?= e(url('staff.bookings.approve', ['no' => $no])) ?>"><?= csrf_field() ?><button class="btn btn-brand"><?= icon('check', 'size-4') ?>Approve</button></form>
                <?php else: ?>
                    <a href="<?= e(url('staff.kyc.show', ['id' => $booking['customer_id']])) ?>" class="btn btn-brand"><?= icon('shield-check', 'size-4') ?>Verify KYC first</a>
                <?php endif ?>
            <?php endif ?>
            <?php if ($actions['reject']): ?><button type="button" class="btn btn-outline" @click="$dispatch('open-modal', 'reject')"><?= icon('x', 'size-4') ?>Reject</button><?php endif ?>
            <?php if ($actions['log_payment'] && $dues['balance'] > 0): ?>
                <button type="button" class="btn <?= $st === BookingStatus::Approved ? 'btn-brand' : 'btn-outline' ?>" @click="$dispatch('open-modal', 'log-payment')"><?= icon('wallet', 'size-4') ?>Log payment</button>
            <?php endif ?>
            <?php if ($actions['confirm'] && $dues['confirmation_met']): ?>
                <form method="post" action="<?= e(url('staff.bookings.confirm', ['no' => $no])) ?>"><?= csrf_field() ?><button class="btn btn-brand" <?= $kyc !== KycStatus::Verified ? 'disabled title="Verify KYC first"' : '' ?>><?= icon('circle-check', 'size-4') ?>Confirm</button></form>
            <?php endif ?>
            <?php if ($actions['check_in'] && count($openIn) < count($current)): ?>
                <form method="post" action="<?= e(url('staff.bookings.checkin', ['no' => $no])) ?>"><?= csrf_field() ?><button class="btn btn-brand"><?= icon('log-in', 'size-4') ?>Check in<?= count($current) > 1 ? ' all' : '' ?></button></form>
            <?php endif ?>
            <?php if ($actions['check_in'] && $openIn !== []): ?>
                <form method="post" action="<?= e(url('staff.bookings.checkout', ['no' => $no])) ?>"><?= csrf_field() ?><button class="btn btn-outline"><?= icon('log-out', 'size-4') ?>Check out<?= count($openIn) > 1 ? ' all' : '' ?></button></form>
            <?php endif ?>
            <?php if ($actions['handover'] || $actions['extend'] || $actions['early_exit'] || $actions['cancel']): ?>
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" class="btn btn-ghost" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu"><?= icon('ellipsis', 'size-4') ?>More</button>
                    <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-2xl bg-white p-1.5 shadow-xl ring-1 ring-line" role="menu">
                        <?php if ($actions['handover']): ?><a role="menuitem" href="<?= e(url('staff.bookings.handover', ['no' => $no])) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold hover:bg-surface"><?= icon('move', 'size-4 text-muted') ?>Hand over seat</a><?php endif ?>
                        <?php if ($actions['extend']): ?><a role="menuitem" href="<?= e(url('staff.bookings.extend', ['no' => $no])) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold hover:bg-surface"><?= icon('calendar-clock', 'size-4 text-muted') ?>Extend / renew</a><?php endif ?>
                        <?php if ($actions['early_exit']): ?><button type="button" role="menuitem" @click="open = false; $dispatch('open-modal', 'early-exit')" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold hover:bg-surface"><?= icon('log-out', 'size-4 text-muted') ?>End early</button><?php endif ?>
                        <?php if ($actions['cancel']): ?><button type="button" role="menuitem" @click="open = false; $dispatch('open-modal', 'cancel')" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50"><?= icon('circle-x', 'size-4') ?>Cancel booking</button><?php endif ?>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>
</section>

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
    <div class="min-w-0 space-y-6">
        <!-- Customer -->
        <section class="card card-body">
            <div class="flex flex-wrap items-center gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-full bg-brand-600 text-lg font-bold text-white"><?= e(mb_strtoupper(mb_substr((string) $customer['name'], 0, 1))) ?></span>
                <div class="min-w-48 flex-1">
                    <p class="truncate font-bold"><?= e($customer['name']) ?></p>
                    <p class="font-mono text-sm text-muted"><?= e($customer['unique_id'] ?? 'No Unique ID yet') ?></p>
                </div>
                <?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
                <?php if ($customer['unique_id']): ?><a class="btn btn-outline btn-sm" href="<?= e(url('staff.visitors.show', ['ref' => $customer['unique_id']])) ?>"><?= icon('user-round', 'size-4') ?>Profile</a><?php endif ?>
            </div>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                <div><dt class="text-xs font-bold text-muted uppercase">Mobile</dt><dd class="font-semibold"><?= e(format_phone((string) $customer['mobile']) ?: '—') ?></dd></div>
                <div><dt class="text-xs font-bold text-muted uppercase">Email</dt><dd class="truncate font-semibold"><?= e($customer['email'] ?: '—') ?></dd></div>
                <?php foreach ($ids as [$idLabel, $idValue]): ?>
                    <div><dt class="text-xs font-bold text-muted uppercase"><?= e($idLabel) ?></dt><dd class="font-mono font-semibold"><?= e($idValue) ?></dd></div>
                <?php endforeach ?>
            </dl>
            <?php if ($kyc !== KycStatus::Verified): ?>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                    <span><?= icon('triangle-alert', 'size-4 inline') ?> KYC must be verified before this booking can be approved or confirmed.</span>
                    <a class="font-bold hover:underline" href="<?= e(url('staff.kyc.show', ['id' => $booking['customer_id']])) ?>">Verify KYC first →</a>
                </div>
            <?php endif ?>
            <?php if ($outstanding['due_now'] > 0): ?>
                <p class="mt-3 text-sm text-red-700"><?= icon('circle-alert', 'size-4 inline') ?> Outstanding across all bookings: <b><?= e($m($outstanding['due_now'])) ?></b> due now<?= count($outstanding['bookings']) > 1 ? ' (' . count($outstanding['bookings']) . ' bookings)' : '' ?>.</p>
            <?php endif ?>
        </section>

        <!-- Seats -->
        <section class="card card-body">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold">Seats &amp; period</h2>
                <?php if ($actions['handover']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('staff.bookings.handover', ['no' => $no])) ?>"><?= icon('move', 'size-4') ?>Hand over</a><?php endif ?>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold text-muted uppercase">Dates</dt><dd class="mt-1 text-sm font-bold"><?= e(format_date($booking['start_date'], 'd M') . ($booking['start_date'] !== $booking['end_date'] ? ' → ' . format_date($booking['end_date'], 'd M Y') : ' ' . format_date($booking['start_date'], 'Y'))) ?></dd></div>
                <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold text-muted uppercase"><?= $hourly ? 'Time' : 'Duration' ?></dt><dd class="mt-1 text-sm font-bold"><?= $hourly ? e(substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)) : e((string) ($q['duration']['label'] ?? '')) ?></dd></div>
                <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold text-muted uppercase">Source</dt><dd class="mt-1 text-sm font-bold"><?= e(BookingSource::from((string) $booking['source'])->label()) ?></dd></div>
                <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold text-muted uppercase">Payment rule</dt><dd class="mt-1 text-sm font-bold"><?= e(PaymentRule::from((string) $booking['payment_rule'])->label()) ?></dd></div>
            </dl>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-line">
                <table class="table text-sm">
                    <thead><tr><th>Seat</th><th>Zone</th><th class="hidden xl:table-cell">From → to</th><th class="text-right">Amount</th><th>Today</th></tr></thead>
                    <tbody><?php foreach ($currentSeats as $s):
                        $cur = null;
                        foreach ($current as $c) {
                            if ((int) $c['id'] === (int) $s['id']) {
                                $cur = $c;
                            }
                        } ?>
                        <tr>
                            <td class="font-bold whitespace-nowrap"><?= e($s['kind'] === 'seat' ? $s['code'] : $s['label'] . ' (' . $s['code'] . ')') ?></td>
                            <td class="whitespace-nowrap"><?= e($s['floor_name'] . ' · ' . $s['zone_name']) ?></td>
                            <td class="hidden whitespace-nowrap text-muted xl:table-cell"><?= e(format_date($s['start_date'], 'd M') . ' → ' . format_date($s['end_date'], 'd M')) ?></td>
                            <td class="text-right font-semibold whitespace-nowrap tabular-nums"><?= e(money($s['amount'], 2)) ?></td>
                            <td class="whitespace-nowrap">
                                <?php if ($cur !== null && $cur['open_checkin_id'] !== null): ?>
                                    <span class="inline-flex items-center gap-2"><?= $this->component('badge', ['label' => 'In · ' . format_date((string) $cur['checked_in_at'], 'g:i a'), 'tone' => 'success', 'dot' => true]) ?>
                                    <?php if ($actions['check_in']): ?><form method="post" action="<?= e(url('staff.bookings.checkout', ['no' => $no])) ?>"><?= csrf_field() ?><input type="hidden" name="booking_seat_id" value="<?= (int) $s['id'] ?>"><button class="text-xs font-semibold text-brand-700 hover:underline">Check out</button></form><?php endif ?></span>
                                <?php elseif ($cur !== null && $actions['check_in']): ?>
                                    <form method="post" action="<?= e(url('staff.bookings.checkin', ['no' => $no])) ?>"><?= csrf_field() ?><input type="hidden" name="booking_seat_id" value="<?= (int) $s['id'] ?>"><button class="btn btn-outline btn-sm !py-1"><?= icon('log-in', 'size-3.5') ?>Check in</button></form>
                                <?php else: ?><span class="text-xs text-muted">—</span><?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?></tbody>
                </table>
            </div>
            <?php if ($history !== []): ?>
                <h3 class="mt-5 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Handover history</h3>
                <ul class="space-y-1.5 text-sm">
                    <?php foreach ($history as $h): ?>
                        <li class="flex items-center gap-2 rounded-xl bg-surface px-3 py-2"><?= icon('move', 'size-4 text-muted') ?><span><b><?= e($h['code']) ?></b> → <b><?= e((string) $h['transferred_to_code']) ?></b> on <?= e(format_date((string) $h['released_at'], 'd M Y, g:i a')) ?></span><span class="truncate text-xs text-muted"><?= e(preg_replace('/^handover: /', '', (string) $h['released_reason'])) ?></span></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
            <?php foreach ($maps as $map): ?>
                <div class="mt-5">
                    <p class="mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase"><?= e($map['floor']['name']) ?></p>
                    <?= $this->partial('partials/booking/minimap', ['map' => $map]) ?>
                </div>
            <?php endforeach ?>
            <?php if ($facilities !== []): ?>
                <h3 class="mt-5 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Add-ons</h3>
                <div class="flex flex-wrap gap-2"><?php foreach ($facilities as $f): ?><span class="rounded-full bg-surface px-3 py-1 text-sm font-semibold ring-1 ring-line"><?= e((string) $f['emoji']) ?> <?= e($f['name']) ?> × <?= (int) $f['qty'] ?> · <?= e(money($f['amount'], 2)) ?></span><?php endforeach ?></div>
            <?php endif ?>
            <?php if (!empty($booking['override_reason'])): ?><p class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-800"><b>Override:</b> <?= e($booking['override_reason']) ?></p><?php endif ?>
            <?php if (!empty($booking['exit_reason'])): ?><p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><b>Early exit:</b> <?= e($booking['exit_reason']) ?> (planned end <?= e(format_date((string) $booking['original_end_date'])) ?>)</p><?php endif ?>
            <?php if (!empty($booking['notes'])): ?><p class="mt-4 rounded-xl bg-surface p-3 text-sm"><?= e($booking['notes']) ?></p><?php endif ?>
        </section>

        <!-- Money -->
        <?php if ($st->billable()): ?>
            <section class="card card-body" id="payments">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold">Payments &amp; dues</h2>
                    <?php if ($actions['log_payment'] && $dues['balance'] > 0): ?><button type="button" class="btn btn-brand btn-sm" @click="$dispatch('open-modal', 'log-payment')"><?= icon('plus', 'size-4') ?>Log payment</button><?php endif ?>
                </div>
                <?= $this->partial('partials/booking/dues', ['dues' => $dues, 'booking' => $booking, 'staff' => true]) ?>
                <h3 class="mt-6 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Payment history</h3>
                <?= $this->partial('partials/booking/payments', ['payments' => $payments, 'staff' => true, 'canVoid' => $actions['void_payment']]) ?>
                <p class="mt-3 text-xs text-muted">Logged payments count towards dues straight away; Finance verifies them and issues receipts / GST invoices.</p>
            </section>
        <?php elseif ($payments !== []): ?>
            <section class="card card-body"><h2 class="mb-4 text-lg font-bold">Payments</h2><?= $this->partial('partials/booking/payments', ['payments' => $payments, 'staff' => true, 'canVoid' => false]) ?></section>
        <?php endif ?>

        <?php if ($q !== null): ?><section class="card card-body"><h2 class="mb-4 text-lg font-bold">Price (quote snapshot)</h2><?= $this->partial('partials/booking/price-summary', ['q' => $q]) ?></section><?php endif ?>

        <!-- Activity -->
        <section class="card card-body">
            <h2 class="mb-4 text-lg font-bold">Activity</h2>
            <?php if ($activity === []): ?><p class="text-sm text-muted">Nothing recorded yet.</p><?php else: ?>
                <ol class="space-y-3">
                    <?php foreach ($activity as $a): $new = json_decode((string) ($a['new_values'] ?? ''), true) ?: []; ?>
                        <li class="flex gap-3 text-sm">
                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-400"></span>
                            <div class="min-w-0">
                                <p><b><?= e($activityLabels[$a['action']] ?? ucfirst(str_replace(['booking.', '_', '.'], ['', ' ', ' '], (string) $a['action']))) ?></b>
                                    <?php if (isset($new['amount'])): ?> · <?= e(money($new['amount'], 2)) ?><?php endif ?>
                                    <?php if (isset($new['seats']) && is_array($new['seats'])): ?> · <?= e(implode(', ', $new['seats'])) ?><?php endif ?>
                                    <?php if (isset($new['renewal'])): ?> · <?= e($new['renewal']) ?><?php endif ?>
                                    <span class="text-muted">— <?= e($a['actor_name']) ?></span></p>
                                <p class="text-xs text-muted"><?= e(format_date((string) $a['created_at'], 'd M Y, g:i a')) ?><?= $a['reason'] ? ' · ' . e($a['reason']) : '' ?></p>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ol>
            <?php endif ?>
        </section>
    </div>

    <aside class="space-y-6 lg:sticky lg:top-24">
        <section class="card card-body">
            <h2 class="mb-5 text-lg font-bold">Status</h2>
            <?= $this->partial('partials/booking/timeline', ['timeline' => $timeline]) ?>
        </section>
        <?php if ($renewedFrom !== null || $renewal !== null): ?>
            <section class="card card-body space-y-2 text-sm">
                <h2 class="text-base font-bold">Linked bookings</h2>
                <?php if ($renewedFrom !== null): ?><p>Extension of <a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $renewedFrom['booking_no']])) ?>"><?= e($renewedFrom['booking_no']) ?></a></p><?php endif ?>
                <?php if ($renewal !== null): ?><p>Renewed as <a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $renewal['booking_no']])) ?>"><?= e($renewal['booking_no']) ?></a> · <?= e(BookingStatus::from((string) $renewal['status'])->label()) ?></p><?php endif ?>
            </section>
        <?php endif ?>
        <?php if ($checkinHistory !== []): ?>
            <section class="card card-body">
                <h2 class="mb-3 text-base font-bold">Check-ins</h2>
                <ul class="space-y-2 text-sm">
                    <?php foreach (array_slice($checkinHistory, 0, 8) as $c): ?>
                        <li class="flex items-start justify-between gap-2">
                            <span><b><?= e($c['code']) ?></b> <span class="text-muted">· <?= e(format_date((string) $c['checked_in_at'], 'd M, g:i a')) ?><?= $c['checked_out_at'] ? ' → ' . e(format_date((string) $c['checked_out_at'], 'g:i a')) : '' ?></span></span>
                            <span class="text-xs text-muted uppercase"><?= e($c['method']) ?></span>
                        </li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endif ?>
    </aside>
</div>

<!-- Modals -->
<?php if ($actions['log_payment']): ?>
    <?php $this->begin('modal', ['id' => 'log-payment', 'title' => 'Log payment · ' . $no, 'size' => 'lg']) ?>
        <form method="post" enctype="multipart/form-data" action="<?= e(url('staff.payments.store', ['no' => $no])) ?>" class="space-y-4"
              x-data="paymentForm(<?= e(json_encode(['mode' => old('mode', 'upi'), 'kind' => old('kind', $suggestedKind), 'amount' => old('amount', $quick[0][0] ?? ''), 'refLabels' => $refLabels])) ?>)">
            <?= csrf_field() ?>
            <div class="rounded-2xl bg-surface p-3 text-xs text-muted">
                <b class="text-ink"><?= e($dues['requirement']['label']) ?>:</b> <?= e($dues['requirement']['summary']) ?>
                <span class="mt-1 block">Balance <b class="text-ink"><?= e($m($dues['balance'])) ?></b> · due now <b class="text-ink"><?= e($m($dues['due_now'])) ?></b></span>
            </div>
            <?php if ($quick !== []): ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($quick as [$amt, $lbl, $kindKey]): ?>
                        <button type="button" class="chip !py-1.5 text-xs" @click="fill(<?= e(json_encode($amt)) ?>, '<?= e($kindKey) ?>')"><?= e($lbl) ?> · <?= e($m($amt)) ?></button>
                    <?php endforeach ?>
                    <?php if ($dues['rule'] === PaymentRule::SecurityDeposit && isset($quick[1])): ?>
                        <span class="self-center text-xs text-muted">Log the deposit and the first month as two payments.</span>
                    <?php endif ?>
                </div>
            <?php endif ?>
            <div class="grid gap-4 sm:grid-cols-2">
                <?= $this->component('select', ['name' => 'kind', 'label' => 'For', 'options' => $kinds, 'required' => true, 'attrs' => ['x-model' => 'kind']]) ?>
                <?= $this->component('select', ['name' => 'mode', 'label' => 'Mode', 'options' => $modes, 'required' => true, 'attrs' => ['x-model' => 'mode']]) ?>
                <?= $this->component('input', ['name' => 'amount', 'label' => 'Amount (₹)', 'type' => 'number', 'required' => true, 'attrs' => ['x-model' => 'amount', 'step' => '0.01', 'min' => '1', 'inputmode' => 'decimal']]) ?>
                <?= $this->component('input', ['name' => 'paid_on', 'label' => 'Paid on', 'type' => 'date', 'required' => true, 'value' => $today, 'attrs' => ['max' => $today]]) ?>
                <div class="sm:col-span-2">
                    <label for="f-reference_no" class="label"><span x-text="refLabel">Reference</span> <span x-show="needsRef" class="text-accent-500" aria-hidden="true">*</span></label>
                    <input id="f-reference_no" name="reference_no" value="<?= e((string) old('reference_no')) ?>" class="<?= e(class_names('input', ['input-error' => errors('reference_no') !== null])) ?>" :required="needsRef" maxlength="100" autocomplete="off" placeholder="e.g. 423198765012">
                    <?php if (errors('reference_no') !== null): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e((string) errors('reference_no')) ?></p><?php else: ?><p class="help" x-show="needsRef">Required for every non-cash payment.</p><?php endif ?>
                </div>
                <div class="sm:col-span-2">
                    <label for="f-proof" class="label">Proof <span class="font-normal text-muted">(optional — screenshot, cheque scan; PDF/JPG/PNG, 5 MB)</span></label>
                    <input id="f-proof" type="file" name="proof" accept="<?= e(DocumentStore::acceptAttribute()) ?>" class="block w-full text-sm file:mr-3 file:rounded-full file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                    <?php if (errors('proof') !== null): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e((string) errors('proof')) ?></p><?php endif ?>
                </div>
                <?= $this->component('textarea', ['name' => 'remarks', 'label' => 'Notes', 'rows' => 2, 'class' => 'sm:col-span-2', 'placeholder' => 'Optional']) ?>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted">Status <b>Logged</b> — Finance verifies it later.<?= $st === BookingStatus::Approved ? ' The booking confirms automatically once the requirement is met.' : '' ?></p>
                <div class="flex gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'log-payment')">Cancel</button><button class="btn btn-brand"><?= icon('check', 'size-4') ?>Log payment</button></div>
            </div>
        </form>
    <?= $this->end() ?>
<?php endif ?>

<?php if ($actions['reject']): ?>
    <?php $this->begin('modal', ['id' => 'reject', 'title' => 'Reject request ' . $no . '?', 'size' => 'sm']) ?>
        <form method="post" action="<?= e(url('staff.bookings.reject', ['no' => $no])) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <p>The seats are released and the visitor is emailed the reason.</p>
            <?= $this->component('textarea', ['name' => 'reason', 'label' => 'Reason', 'rows' => 3, 'required' => true, 'placeholder' => 'e.g. Seats reserved for a government programme on these dates']) ?>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'reject')">Back</button><button class="btn btn-danger">Reject request</button></div>
        </form>
    <?= $this->end() ?>
<?php endif ?>

<?php if ($actions['cancel']): ?>
    <?php $this->begin('modal', ['id' => 'cancel', 'title' => 'Cancel booking ' . $no . '?', 'size' => 'sm']) ?>
        <form method="post" action="<?= e(url('staff.bookings.cancel', ['no' => $no])) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <p>The seats are released immediately<?= $dues['paid'] > 0 ? ' — ' . e($m($dues['paid'])) . ' was paid; Finance handles any refund' : '' ?>.</p>
            <?= $this->component('textarea', ['name' => 'reason', 'label' => 'Reason', 'rows' => 3, 'required' => true]) ?>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'cancel')">Back</button><button class="btn btn-danger">Cancel booking</button></div>
        </form>
    <?= $this->end() ?>
<?php endif ?>

<?php if ($actions['early_exit']): ?>
    <?php $this->begin('modal', ['id' => 'early-exit', 'title' => 'End ' . $no . ' early', 'size' => 'sm']) ?>
        <form method="post" action="<?= e(url('staff.bookings.early_exit', ['no' => $no])) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <p>Seats are released from the chosen date (the day before is the last day). Rent periods after that are cancelled; nothing is refunded automatically.</p>
            <?php $minExit = max($today, date('Y-m-d', (int) strtotime($booking['start_date'] . ' +1 day'))); ?>
            <?= $this->component('input', ['name' => 'release_from', 'label' => 'Release seats from', 'type' => 'date', 'required' => true, 'value' => $minExit, 'attrs' => ['min' => $minExit, 'max' => (string) $booking['end_date']]]) ?>
            <?= $this->component('textarea', ['name' => 'reason', 'label' => 'Reason', 'rows' => 2, 'required' => true]) ?>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'early-exit')">Back</button><button class="btn btn-danger">End early</button></div>
        </form>
    <?= $this->end() ?>
<?php endif ?>
