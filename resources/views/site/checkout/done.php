<?php
/**
 * Booking request sent (confirmation).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking
 * @var list<array<string, mixed>> $seats
 * @var list<array<string, mixed>> $facilities
 * @var array<string, mixed> $customer
 */
use App\Enums\PaymentRule;

$this->layout('layouts/site');
$q = json_decode((string) $booking['quote_json'], true) ?: null;
$rule = PaymentRule::from((string) $booking['payment_rule']);
?>
<div class="relative isolate overflow-hidden bg-gradient-to-b from-emerald-50 via-white to-white">
    <div class="container-page max-w-3xl py-12 text-center sm:py-16">
        <span class="mx-auto grid size-20 animate-pop place-items-center rounded-full bg-emerald-500 text-white shadow-[0_20px_40px_-12px_rgb(16_185_129/.6)]"><?= icon('party-popper', 'size-9') ?></span>
        <h1 class="mt-6 text-4xl font-extrabold sm:text-5xl">Request sent!</h1>
        <p class="mx-auto mt-3 max-w-xl text-lg text-muted">Thanks, <?= e(explode(' ', (string) $customer['name'])[0]) ?>. Your seats are reserved while the Centre Manager reviews your request — we’ll email you as soon as it’s approved.</p>
        <p class="mt-6 inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 font-mono text-lg font-bold text-brand-900 shadow-[var(--shadow-card)] ring-1 ring-line"><?= icon('ticket', 'size-5 text-accent-500') ?><?= e($booking['booking_no']) ?></p>
    </div>
</div>
<div class="container-page max-w-3xl pb-16">
    <div class="card overflow-hidden">
        <div class="grid grid-cols-1 gap-px bg-line sm:grid-cols-3">
            <?php foreach ([
                ['Space', (string) $booking['category_name']],
                ['Seats', (string) $booking['seat_codes']],
                [$booking['start_time'] ? 'When' : 'Dates', $booking['start_time']
                    ? format_date($booking['start_date'], 'd M Y') . ' · ' . substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)
                    : format_date($booking['start_date'], 'd M') . ' → ' . format_date($booking['end_date'])],
            ] as [$k, $v]): ?>
                <div class="bg-white p-5"><p class="text-xs font-bold tracking-wide text-muted uppercase"><?= e($k) ?></p><p class="mt-1 font-bold"><?= e($v) ?></p></div>
            <?php endforeach ?>
        </div>
        <div class="grid grid-cols-1 gap-8 p-6 sm:grid-cols-2">
            <div>
                <h2 class="mb-4 font-bold">What happens next</h2>
                <ol class="space-y-4 text-sm">
                    <li class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><?= icon('check', 'size-3.5') ?></span><span><b>Request received</b><br><span class="text-muted">Confirmation emailed to you.</span></span></li>
                    <li class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-600 text-xs font-bold text-white">2</span><span><b>Approval</b><br><span class="text-muted">The Centre Manager checks your KYC and the seats (usually within one working day).</span></span></li>
                    <li class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-white text-xs font-bold ring-1 ring-line">3</span><span><b><?= e($rule === PaymentRule::Advance ? 'Advance payment' : 'Security deposit') ?></b><br><span class="text-muted">Pay at the front desk (UPI / card / bank transfer) — your seats are then confirmed and allotted.</span></span></li>
                </ol>
            </div>
            <div>
                <?php if ($q !== null): ?><?= $this->partial('partials/booking/price-summary', ['q' => $q]) ?><?php endif ?>
            </div>
        </div>
        <div class="flex flex-col gap-3 border-t border-line bg-surface p-5 sm:flex-row sm:justify-end">
            <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-outline"><?= icon('building-2', 'size-4') ?>Explore more spaces</a>
            <a href="<?= e(url('portal.bookings.show', ['no' => $booking['booking_no']])) ?>" class="btn btn-brand"><?= icon('calendar-check', 'size-4') ?>View my booking</a>
        </div>
    </div>
</div>
