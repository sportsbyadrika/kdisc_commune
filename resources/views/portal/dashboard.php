<?php
/**
 * Visitor dashboard: ID card + QR, KYC timeline, next steps and placeholder tiles for later batches.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var array<string, mixed> $account
 * @var App\Enums\CustomerType $type
 * @var App\Enums\KycStatus $kyc
 * @var string|null $qr
 * @var int $nextStep
 * @var array<int, list<string>> $missing
 * @var int $bookingCount
 * @var list<array<string, mixed>> $current  approved / confirmed / active bookings
 * @var array{due_now: float, balance: float, bookings: list<array<string, mixed>>} $outstanding
 * @var string $today
 * @var int $documentCount invoices + receipts + credit notes + refund vouchers
 */
use App\Enums\KycStatus;

$first = explode(' ', (string) $customer['name'])[0];
$this->layout('layouts/portal', ['heading' => 'Hello, ' . $first, 'subheading' => 'Your Commune account at a glance.']);
$submitted = (int) $customer['profile_step'] >= 4 && $customer['unique_id'] !== null;
$tiles = [
    ['calendar-check', 'My bookings', ($bookingCount ?? 0) > 0 ? 'Track your requests and active seats.' : 'Request seats, cabins and the conference room from the Space Explorer.', ($bookingCount ?? 0) > 0 ? $bookingCount . ' booking' . ($bookingCount === 1 ? '' : 's') : 'Book now', url('portal.bookings')],
    ['wallet', 'Dues & payments', $outstanding['due_now'] > 0 ? 'Pay at the front desk — see each booking for the breakdown and history.' : ($outstanding['balance'] > 0 ? 'Nothing due right now; later rent periods are listed per booking.' : 'Nothing outstanding. Payment history is on each booking.'), $outstanding['due_now'] > 0 ? money($outstanding['due_now'], fmod($outstanding['due_now'], 1.0) ? 2 : 0) . ' due' : 'All clear', url('portal.bookings')],
    ['receipt-indian-rupee', 'Invoices & receipts', 'Download GST invoices, receipts and allotment letters as PDF.', $documentCount > 0 ? $documentCount . ' document' . ($documentCount === 1 ? '' : 's') : 'None yet', url('portal.invoices')],
];
?>
<?php if (!$submitted): ?>
    <div class="mb-8 flex flex-col gap-4 overflow-hidden rounded-3xl bg-gradient-to-r from-brand-700 to-brand-900 p-6 text-white shadow-[var(--shadow-card)] sm:flex-row sm:items-center sm:justify-between sm:p-8">
        <div>
            <p class="text-xs font-bold tracking-[0.16em] text-white/60 uppercase">Next step</p>
            <h2 class="mt-1 text-2xl font-extrabold !text-white">Complete your profile &amp; KYC</h2>
            <p class="mt-1 max-w-xl text-white/75">Step <?= $nextStep ?> of 4. Submit it to get your Unique Visitor ID — you’ll need it for bookings.</p>
        </div>
        <a href="<?= e(url('portal.wizard', ['step' => $nextStep])) ?>" class="btn btn-primary btn-lg shrink-0"><?= (int) $customer['profile_step'] > 0 ? 'Continue' : 'Start now' ?> <?= icon('arrow-right', 'size-4') ?></a>
    </div>
<?php elseif ($kyc === KycStatus::Rejected): ?>
    <?php $this->begin('alert', ['tone' => 'danger', 'title' => 'Your KYC needs changes', 'class' => 'mb-8']) ?>
        <p class="mt-1"><?= e((string) ($customer['kyc_remarks'] ?? '')) ?></p>
        <a href="<?= e(url('portal.wizard', ['step' => 1])) ?>" class="btn btn-danger btn-sm mt-3">Update &amp; re-submit</a>
    <?= $this->end() ?>
<?php endif ?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-start">
    <div class="lg:col-span-3">
        <?= $this->partial('partials/visitor/id-card') ?>
        <?php if (!empty($customer['unique_id'])): ?>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 px-1">
                <p class="text-xs text-muted">Show the QR at the front desk to check in — or print your card.</p>
                <a href="<?= e(url('portal.id_card')) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm"><?= icon('file-down', 'size-4') ?>Download ID card (PDF)</a>
            </div>
        <?php endif ?>
    </div>
    <div class="space-y-6 lg:col-span-2 lg:row-span-2">
        <section class="card card-body">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-bold">KYC status</h2>
                <?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
            </div>
            <div class="mt-5"><?= $this->partial('partials/visitor/kyc-timeline') ?></div>
        </section>
        <section class="card card-body">
            <h2 class="text-base font-bold">Account</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted">Sign-in email</dt><dd class="truncate font-medium"><?= e($account['email'] ?? '') ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Visitor type</dt><dd class="font-medium"><?= e($type->label()) ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Last sign-in</dt><dd class="font-medium"><?= e(format_date($account['last_login_at'] ?? null, 'd M Y, g:i a')) ?: '—' ?></dd></div>
            </dl>
            <div class="mt-5 flex flex-wrap gap-2">
                <a href="<?= e(url('portal.profile')) ?>" class="btn btn-outline btn-sm"><?= icon('id-card', 'size-4') ?> View profile</a>
                <a href="<?= e(url('portal.documents')) ?>" class="btn btn-outline btn-sm"><?= icon('file-text', 'size-4') ?> Documents</a>
            </div>
        </section>
    </div>
    <div class="space-y-6 lg:col-span-3">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?php foreach ($tiles as [$ico, $label, $text, $badge, $href]): ?>
                <<?= $href !== null ? 'a href="' . e($href) . '"' : 'div' ?> class="card card-body flex flex-col <?= $href !== null ? 'card-hover' : '' ?>">
                    <div class="flex items-center justify-between">
                        <span class="grid size-10 place-items-center rounded-xl <?= $href !== null ? 'bg-brand-50 text-brand-700' : 'bg-surface text-ink/60' ?>"><?= icon($ico, 'size-5') ?></span>
                        <?= $this->component('badge', ['label' => $badge, 'tone' => $href !== null ? 'brand' : 'neutral']) ?>
                    </div>
                    <h3 class="mt-4 text-sm font-bold"><?= e($label) ?></h3>
                    <p class="mt-1 text-xs text-muted"><?= e($text) ?></p>
                </<?= $href !== null ? 'a' : 'div' ?>>
            <?php endforeach ?>
        </div>
        <?php if ($current !== []): ?>
            <section class="card overflow-hidden">
                <h3 class="px-5 pt-5 font-bold">Your seats</h3>
                <ul class="mt-2 divide-y divide-line">
                    <?php foreach (array_slice($current, 0, 4) as $b):
                        $st = App\Enums\BookingStatus::from((string) $b['status']);
                        $left = (int) round((strtotime((string) $b['end_date']) - strtotime($today)) / 86400);
                        $startsIn = (int) round((strtotime((string) $b['start_date']) - strtotime($today)) / 86400); ?>
                        <li class="flex flex-wrap items-center gap-3 px-5 py-4">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2"><a class="font-mono text-sm font-bold text-brand-800 hover:underline" href="<?= e(url('portal.bookings.show', ['no' => $b['booking_no']])) ?>"><?= e($b['booking_no']) ?></a><?= $this->component('badge', ['label' => $st->label(), 'tone' => $st->tone(), 'dot' => true]) ?></p>
                                <p class="mt-0.5 truncate text-sm font-semibold"><?= e($b['category_name']) ?> · <?= e((string) $b['seat_codes']) ?></p>
                            </div>
                            <div class="text-right">
                                <?php if ($st === App\Enums\BookingStatus::Approved): ?>
                                    <p class="text-sm font-bold text-amber-700">Pay to confirm</p><p class="text-xs text-muted"><?= !empty($b['payment_due_by']) ? 'by ' . e(format_date((string) $b['payment_due_by'], 'd M')) : '' ?></p>
                                <?php elseif ($startsIn > 0): ?>
                                    <p class="font-display text-2xl font-extrabold tabular-nums"><?= $startsIn ?></p><p class="text-xs text-muted">day<?= $startsIn === 1 ? '' : 's' ?> to start</p>
                                <?php else: ?>
                                    <p class="font-display text-2xl font-extrabold tabular-nums <?= $left <= 7 ? 'text-accent-600' : '' ?>"><?= max(0, $left) ?></p><p class="text-xs text-muted">day<?= $left === 1 ? '' : 's' ?> left · ends <?= e(format_date((string) $b['end_date'], 'd M')) ?></p>
                                <?php endif ?>
                            </div>
                            <?php if ($st !== App\Enums\BookingStatus::Approved && $b['start_time'] === null && $left <= 30): ?>
                                <a href="<?= e(url('portal.bookings.renew', ['no' => $b['booking_no']])) ?>" class="btn btn-primary btn-sm"><?= icon('refresh-cw', 'size-4') ?>Renew</a>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endif ?>
        <div class="card card-body flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-bold">Explore the building</h3>
                <p class="text-sm text-muted">Browse floors, zones and seat types while your KYC is being verified.</p>
            </div>
            <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-outline shrink-0"><?= icon('building-2', 'size-4') ?> Open Space Explorer</a>
        </div>
    </div>
</div>
