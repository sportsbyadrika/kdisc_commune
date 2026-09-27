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
 */
use App\Enums\KycStatus;

$first = explode(' ', (string) $customer['name'])[0];
$this->layout('layouts/portal', ['heading' => 'Hello, ' . $first, 'subheading' => 'Your Commune account at a glance.']);
$submitted = (int) $customer['profile_step'] >= 4 && $customer['unique_id'] !== null;
$tiles = [
    ['calendar-check', 'My bookings', ($bookingCount ?? 0) > 0 ? 'Track your requests and active seats.' : 'Request seats, cabins and the conference room from the Space Explorer.', ($bookingCount ?? 0) > 0 ? $bookingCount . ' booking' . ($bookingCount === 1 ? '' : 's') : 'Book now', url('portal.bookings')],
    ['wallet', 'Dues & payments', 'See advances, deposits and rent due, with payment history.', 'Coming soon', null],
    ['receipt-indian-rupee', 'Invoices & receipts', 'Download GST invoices and receipts as PDF.', 'Coming soon', null],
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

<div class="grid gap-6 lg:grid-cols-5 lg:items-start">
    <div class="lg:col-span-3"><?= $this->partial('partials/visitor/id-card') ?></div>
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
        <div class="grid gap-4 sm:grid-cols-3">
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
        <div class="card card-body flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-bold">Explore the building</h3>
                <p class="text-sm text-muted">Browse floors, zones and seat types while your KYC is being verified.</p>
            </div>
            <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-outline shrink-0"><?= icon('building-2', 'size-4') ?> Open Space Explorer</a>
        </div>
    </div>
</div>
