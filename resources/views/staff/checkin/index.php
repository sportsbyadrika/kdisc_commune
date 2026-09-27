<?php
/**
 * Check-in desk: scan the QR on the visitor's ID card (BarcodeDetector, when the browser supports it) or type the
 * Unique ID / name / mobile → today's bookings with per-seat check-in / check-out.
 *
 * @var App\Core\Template $this
 * @var string $q
 * @var array<string, mixed>|null $customer
 * @var list<array<string, mixed>> $bookings
 * @var list<array<string, mixed>> $matches
 * @var int $checkedIn
 */
use App\Enums\BookingStatus;
use App\Enums\KycStatus;

$this->layout('layouts/staff', ['title' => 'Check-in desk', 'subtitle' => 'Scan the QR on the visitor’s ID card or type their Unique ID.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Check-in']]]);
$back = url('staff.checkins.index', ['id' => $q]);
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/js/frontdesk.js')) ?>"></script>
<?php $this->stop() ?>
<?php $this->start('actions') ?>
<?= $this->component('badge', ['label' => $checkedIn . ' checked in now', 'tone' => 'success', 'icon' => 'users']) ?>
<?php $this->stop() ?>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-[400px_minmax(0,1fr)] lg:items-start">
    <section class="card card-body" x-data="checkinScanner">
        <form method="get" action="<?= e(url('staff.checkins.index')) ?>" x-ref="form" class="space-y-3">
            <label for="ci-id" class="label">Unique Visitor ID</label>
            <div class="flex gap-2">
                <input id="ci-id" x-ref="idInput" name="id" value="<?= e($q) ?>" class="input font-mono uppercase" placeholder="CMN-KTR-I-2026-00001" autocomplete="off" autocapitalize="characters" <?= $q === '' ? 'autofocus' : '' ?>>
                <button class="btn btn-brand shrink-0"><?= icon('search', 'size-4') ?><span class="sr-only sm:not-sr-only">Find</span></button>
            </div>
            <p class="help">Also works with a name, mobile number or email. A USB/Bluetooth QR scanner types into this box.</p>
        </form>
        <div class="mt-5 border-t border-line pt-5">
            <button type="button" class="btn btn-outline w-full" x-show="!scanning" @click="start()"><?= icon('camera', 'size-4') ?>Scan QR with camera</button>
            <div x-show="scanning" x-cloak class="relative overflow-hidden rounded-2xl bg-ink">
                <video x-ref="video" class="aspect-square w-full object-cover" playsinline muted></video>
                <span class="pointer-events-none absolute inset-10 rounded-3xl border-4 border-white/80"></span>
                <button type="button" class="btn btn-light btn-sm absolute right-3 bottom-3" @click="stop()">Stop</button>
            </div>
            <p x-show="error" x-cloak class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900" x-text="error"></p>
            <p x-show="!supported && !error" class="mt-3 text-xs text-muted">Camera scanning needs a browser with the BarcodeDetector API (Chrome / Edge on Android, ChromeOS, macOS). Typing the ID always works.</p>
        </div>
    </section>

    <section class="min-w-0 space-y-4">
        <?php if ($q === ''): ?>
            <?= $this->component('empty', ['icon' => 'scan-line', 'title' => 'Waiting for a visitor', 'text' => 'Scan or type a Unique ID to see today’s bookings and check the visitor in.']) ?>
        <?php elseif ($customer === null && $matches === []): ?>
            <?= $this->component('empty', ['icon' => 'user-search', 'title' => 'No visitor found', 'text' => 'Nobody matches “' . $q . '”. Check the ID or register the visitor.', 'action' => ['label' => 'New visitor', 'href' => url('staff.visitors.create'), 'variant' => 'brand', 'icon' => 'user-plus']]) ?>
        <?php elseif ($customer === null): ?>
            <div class="card card-body">
                <h2 class="text-lg font-bold">Several visitors match “<?= e($q) ?>”</h2>
                <ul class="mt-3 divide-y divide-line">
                    <?php foreach ($matches as $mt): ?>
                        <li><a class="flex items-center justify-between gap-3 py-3 hover:text-brand-700" href="<?= e(url('staff.checkins.index', ['id' => $mt['unique_id'] ?: $mt['name']])) ?>"><span class="font-semibold"><?= e($mt['name']) ?></span><span class="font-mono text-sm text-muted"><?= e($mt['unique_id'] ?? '—') ?></span></a></li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php else: $kyc = KycStatus::tryFrom((string) $customer['kyc_status']) ?? KycStatus::NotSubmitted; ?>
            <div class="card card-body flex flex-wrap items-center gap-4">
                <span class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-xl font-bold text-white"><?= e(mb_strtoupper(mb_substr((string) $customer['name'], 0, 1))) ?></span>
                <div class="min-w-48 flex-1">
                    <p class="text-xl font-extrabold"><?= e($customer['name']) ?></p>
                    <p class="font-mono text-sm text-muted"><?= e($customer['unique_id'] ?? '—') ?> · <?= e(format_phone((string) $customer['mobile'])) ?></p>
                </div>
                <?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
                <?php if ($customer['unique_id']): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('staff.visitors.show', ['ref' => $customer['unique_id']])) ?>">Profile</a><?php endif ?>
            </div>
            <?php if ($bookings === []): ?>
                <?= $this->component('empty', ['icon' => 'calendar', 'title' => 'No booking for today', 'text' => $customer['name'] . ' has no approved, confirmed or active booking covering today.', 'action' => ['label' => 'Book on the map', 'href' => url('staff.explorer', ['customer' => $customer['id']]), 'variant' => 'brand', 'icon' => 'map']]) ?>
            <?php endif ?>
            <?php foreach ($bookings as $b): $st = BookingStatus::from((string) $b['status']); $canCheck = in_array($st, [BookingStatus::Confirmed, BookingStatus::Active], true); ?>
                <article class="card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface/60 px-5 py-3">
                        <div>
                            <a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $b['booking_no']])) ?>"><?= e($b['booking_no']) ?></a>
                            <span class="ml-2 text-sm text-muted"><?= e($b['category_name']) ?> · <?= e(format_date($b['start_date'], 'd M') . ' → ' . format_date($b['end_date'], 'd M Y')) ?><?= $b['start_time'] ? ' · ' . e(substr((string) $b['start_time'], 0, 5) . '–' . substr((string) $b['end_time'], 0, 5)) : '' ?></span>
                        </div>
                        <?= $this->component('badge', ['label' => $st->label(), 'tone' => $st->tone(), 'dot' => true]) ?>
                    </div>
                    <?php if (!$canCheck): ?>
                        <p class="px-5 py-4 text-sm text-amber-900"><?= icon('wallet', 'size-4 inline') ?> Not confirmed yet — <a class="font-bold underline" href="<?= e(url('staff.bookings.show', ['no' => $b['booking_no']])) ?>#payments">log the payment</a> first.</p>
                    <?php endif ?>
                    <ul class="divide-y divide-line">
                        <?php foreach ($b['seats'] as $s): $in = $s['open_checkin_id'] !== null; ?>
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 place-items-center rounded-xl <?= $in ? 'bg-emerald-500 text-white' : 'bg-surface text-muted' ?>"><?= icon('armchair', 'size-5') ?></span>
                                    <div><p class="font-bold"><?= e($s['kind'] === 'seat' ? $s['code'] : $s['label'] . ' (' . $s['code'] . ')') ?></p>
                                        <p class="text-xs <?= $in ? 'text-emerald-700' : 'text-muted' ?>"><?= $in ? 'In since ' . e(format_date((string) $s['checked_in_at'], 'g:i a')) : 'Not checked in' ?></p></div>
                                </div>
                                <?php if ($canCheck): ?>
                                    <form method="post" action="<?= e(url($in ? 'staff.bookings.checkout' : 'staff.bookings.checkin', ['no' => $b['booking_no']])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="booking_seat_id" value="<?= (int) $s['id'] ?>">
                                        <input type="hidden" name="method" value="qr">
                                        <input type="hidden" name="back" value="<?= e($back) ?>">
                                        <button class="btn <?= $in ? 'btn-outline' : 'btn-brand' ?> btn-sm"><?= icon($in ? 'log-out' : 'log-in', 'size-4') ?><?= $in ? 'Check out' : 'Check in' ?></button>
                                    </form>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </article>
            <?php endforeach ?>
        <?php endif ?>
    </section>
</div>
