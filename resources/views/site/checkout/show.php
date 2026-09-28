<?php
/**
 * Checkout review → booking request.
 *
 * @var App\Core\Template $this
 * @var array{units: list<array<string, mixed>>, category: ?App\Enums\SeatCategory, period: ?App\Services\Space\BookingPeriod, expires_at: ?string, expires_in: int} $selection
 * @var App\Services\Pricing\Quote $quote
 * @var array<string, mixed> $customer
 * @var App\Enums\KycStatus $kyc
 * @var bool $canRequest
 * @var string $wizardUrl
 * @var string $back
 */
use App\Enums\KycStatus;

$this->layout('layouts/site');
$q = $quote->toArray();
$category = $quote->category;
$period = $quote->period;
$steps = [['Choose seats', 'done'], ['Review & request', 'current'], ['Approval & payment', 'upcoming']];
?>
<div class="bg-gradient-to-b from-surface to-white">
    <div class="container-page py-8 sm:py-10" x-data="holdTimer(<?= (int) $selection['expires_in'] ?>)">
        <?= $this->component('breadcrumb', ['items' => [['Space Explorer', $back], ['Review & request']]]) ?>

        <ol class="mt-6 flex flex-wrap items-center gap-2 text-sm font-semibold sm:gap-3">
            <?php foreach ($steps as $i => [$label, $state]): ?>
                <li class="flex items-center gap-2 <?= $state === 'upcoming' ? 'text-muted' : 'text-ink' ?>">
                    <span class="grid size-7 place-items-center rounded-full text-xs <?= $state === 'done' ? 'bg-emerald-500 text-white' : ($state === 'current' ? 'bg-brand-600 text-white ring-4 ring-brand-100' : 'bg-white ring-1 ring-line') ?>"><?= $state === 'done' ? icon('check', 'size-3.5') : $i + 1 ?></span>
                    <?= e($label) ?>
                    <?php if ($i < count($steps) - 1): ?><span class="mx-1 hidden h-px w-10 bg-line sm:block"></span><?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>

        <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start">
            <div class="space-y-6">
                <div>
                    <h1 class="text-3xl font-extrabold sm:text-4xl">Review your request</h1>
                    <p class="mt-1 text-muted">Check the details, then send the request — no payment is taken now.</p>
                </div>

                <!-- Hold -->
                <div class="flex items-center gap-4 rounded-2xl p-4 ring-1 transition" :class="expired ? 'bg-red-50 ring-red-200' : (low ? 'bg-amber-50 ring-amber-200' : 'bg-white ring-line')">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl" :class="expired ? 'bg-red-100 text-red-600' : 'bg-brand-50 text-brand-600'"><?= icon('timer', 'size-5') ?></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold" x-show="!expired">Seats held for you · <span class="font-mono tabular-nums" x-text="text"></span></p>
                        <p class="font-bold text-red-700" x-show="expired" x-cloak>Your hold has expired</p>
                        <p class="text-sm text-muted" x-text="expired ? 'Go back to the map to pick your seats again.' : 'Send the request before the timer runs out.'"></p>
                    </div>
                    <a href="<?= e($back) ?>" class="btn btn-outline btn-sm shrink-0"><?= icon('pencil', 'size-4') ?>Change</a>
                </div>

                <!-- Selection -->
                <section class="card card-body">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><span class="grid size-9 place-items-center rounded-xl bg-brand-50 text-brand-700"><?= icon($category->icon(), 'size-5') ?></span><?= e($category->label()) ?></h2>
                        <?= $this->component('badge', ['label' => $quote->duration->label(), 'tone' => 'brand', 'icon' => 'calendar-range']) ?>
                    </div>
                    <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold tracking-wide text-muted uppercase"><?= $period->isHourly() ? 'Date' : 'From' ?></dt><dd class="mt-1 font-bold"><?= e(format_date($period->from, 'D, d M Y')) ?></dd></div>
                        <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold tracking-wide text-muted uppercase"><?= $period->isHourly() ? 'Time' : 'To' ?></dt><dd class="mt-1 font-bold"><?= $period->isHourly() ? e(App\Services\Space\BookingPeriod::hourLabel((string) $period->startTime) . ' – ' . App\Services\Space\BookingPeriod::hourLabel((string) $period->endTime)) : e(format_date($period->to, 'D, d M Y')) ?></dd></div>
                        <div class="rounded-2xl bg-surface p-4"><dt class="text-xs font-bold tracking-wide text-muted uppercase">Seats</dt><dd class="mt-1 font-bold"><?= $quote->seatCount() ?> <?= $quote->seatCount() === 1 ? 'seat' : 'seats' ?></dd></div>
                    </dl>
                    <ul class="mt-5 divide-y divide-line rounded-2xl border border-line">
                        <?php foreach ($selection['units'] as $u): $s = array_values(array_filter($q['seats'], static fn ($x) => $x['seat_id'] === (int) $u['seat_id']))[0] ?? null; ?>
                            <li class="flex items-center gap-3 px-4 py-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-seat-selected text-white"><?= icon('armchair', 'size-4') ?></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-bold"><?= e($u['kind'] === 'seat' ? $u['code'] : $u['label'] . ' (' . $u['code'] . ')') ?></p>
                                    <p class="text-xs text-muted"><?= e($u['floor_name'] . ' · ' . $u['zone_name']) ?><?= $u['kind'] !== 'seat' ? ' · ' . (int) $u['capacity'] . ' chairs' : '' ?></p>
                                </div>
                                <?php if ($s !== null): ?><span class="text-sm font-semibold tabular-nums"><?= e(money($s['amount'], fmod((float) $s['amount'], 1.0) ? 2 : 0)) ?></span><?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                    <?php if ($q['addons'] !== []): ?>
                        <p class="mt-5 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Add-ons</p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($q['addons'] as $a): ?>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-800 ring-1 ring-brand-200"><?= e((string) $a['emoji']) ?> <?= e($a['name']) ?><?= $a['qty'] > 1 ? ' × ' . (int) $a['qty'] : '' ?></span>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </section>

                <!-- Visitor / KYC -->
                <section class="card card-body">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="grid size-11 place-items-center rounded-full bg-brand-600 text-lg font-bold text-white"><?= e(mb_substr((string) $customer['name'], 0, 1)) ?></span>
                            <div>
                                <p class="font-bold"><?= e($customer['name']) ?></p>
                                <p class="font-mono text-xs text-muted"><?= e($customer['unique_id'] ?? 'Unique ID issued when you submit your profile') ?></p>
                            </div>
                        </div>
                        <?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
                    </div>
                    <?php if (!$canRequest): ?>
                        <?php $this->begin('alert', ['tone' => 'warning', 'title' => $kyc === KycStatus::Rejected ? 'Your KYC needs changes first' : 'Complete your profile & KYC first', 'class' => 'mt-5']) ?>
                            <p class="mt-1">Bookings are requested against your Unique Visitor ID. It takes about 5 minutes — your seats stay held while the timer runs, and you can renew the hold on the map.</p>
                            <a href="<?= e($wizardUrl) ?>" class="btn btn-brand btn-sm mt-3"><?= icon('id-card', 'size-4') ?>Complete my profile</a>
                        <?= $this->end() ?>
                    <?php elseif ($kyc === KycStatus::Pending): ?>
                        <p class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><?= icon('info', 'mt-0.5 size-4 shrink-0') ?>Your KYC is being verified. You can send the request now — the booking is confirmed once KYC is verified and payment is logged.</p>
                    <?php endif ?>
                </section>

                <form method="post" action="<?= e(url('spaces.checkout.submit')) ?>" class="card card-body space-y-5" x-data="{ agree: <?= old('terms') ? 'true' : 'false' ?> }">
                    <?= csrf_field() ?>
                    <?= $this->component('textarea', ['name' => 'notes', 'label' => 'Anything we should know?', 'rows' => 2, 'placeholder' => 'Optional — e.g. arriving at 9:30, need a monitor']) ?>
                    <label class="flex cursor-pointer items-start gap-3 text-sm">
                        <input type="checkbox" name="terms" value="1" x-model="agree" class="mt-0.5 size-4.5 rounded border-line accent-brand-600">
                        <span>I agree to the <a href="<?= e(url('terms')) ?>" target="_blank" class="font-semibold text-brand-700 underline">terms of use</a> and house rules, and understand the request is confirmed only after approval and payment.</span>
                    </label>
                    <?php if (errors('terms')): ?><p class="error-text"><?= e((string) errors('terms')) ?></p><?php endif ?>
                    <button type="submit" class="btn btn-primary btn-lg w-full sm:w-auto" <?= $canRequest ? '' : 'disabled' ?> :disabled="<?= $canRequest ? 'false' : 'true' ?> || !agree || expired">
                        <?= icon('send', 'size-5') ?>Send booking request · <?= e(money($quote->grandTotal)) ?>
                    </button>
                </form>
            </div>

            <aside class="lg:sticky lg:top-24">
                <div class="card card-body">
                    <h2 class="mb-4 text-lg font-bold">Price details</h2>
                    <?= $this->partial('partials/booking/price-summary', ['q' => $q]) ?>
                </div>
                <p class="mt-3 flex items-center gap-2 px-2 text-xs text-muted"><?= icon('shield-check', 'size-4') ?>GST invoice issued after payment · SAC <?= e((string) setting('sac_code', '997212')) ?></p>
            </aside>
        </div>
    </div>
</div>
