<?php
/**
 * Visitor record for staff: profile (masked IDs), documents viewer / upload, account & history.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var App\Enums\CustomerType $type
 * @var App\Enums\KycStatus $kyc
 * @var array<string, mixed>|null $signatory
 * @var list<array<string, mixed>> $checklist
 * @var array<string, mixed>|null $account
 * @var string|null $registeredBy
 * @var string|null $verifiedBy
 * @var list<array<string, mixed>> $history
 * @var bool $canEdit
 * @var bool $canUpload
 * @var bool $canVerify
 * @var bool $canViewDocs
 * @var string|null $qr
 */
use App\Enums\AccountStatus;
use App\Enums\CustomerSubCategory;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Services\Visitors\ProfileService;

$ref = Customer::ref($customer);
$sub = CustomerSubCategory::tryFrom((string) ($customer['sub_category'] ?? ''));
$this->layout('layouts/staff', [
    'subtitle' => $type->label() . ($sub !== null ? ' · ' . $sub->label() : '') . ' · registered ' . ($customer['registered_via'] === 'reception' ? 'at the front desk' : 'online') . ' on ' . format_date((string) $customer['created_at']),
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Visitors', url('staff.visitors.index')], [(string) ($customer['unique_id'] ?? $customer['name'])]],
]);
$accountStatus = $account !== null ? AccountStatus::from((string) $account['status']) : null;
$actionLabels = [
    'visitor.register' => 'Registered at the front desk', 'account.register' => 'Registered online', 'profile.basic' => 'Basic details updated',
    'profile.identity' => 'Identity details updated', 'document.upload' => 'Document uploaded', 'document.replace' => 'Document replaced',
    'document.delete' => 'Document deleted', 'kyc.submit' => 'Submitted for KYC', 'kyc.approve' => 'KYC approved', 'kyc.reject' => 'KYC rejected',
    'kyc.document.view' => 'Document viewed',
];
?>
<?php $this->start('actions') ?>
    <?php if ($canVerify && $kyc === KycStatus::Pending): ?>
        <a href="<?= e(url('staff.kyc.show', ['id' => $customer['id']])) ?>" class="btn btn-primary"><?= icon('shield-check', 'size-4') ?> Review KYC</a>
    <?php endif ?>
    <?php if ($canEdit && ($accountStatus === null || $accountStatus === AccountStatus::Pending) && !empty($customer['email'])): ?>
        <form method="post" action="<?= e(url('staff.visitors.invite', ['ref' => $ref])) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline"><?= icon('send', 'size-4') ?> <?= $accountStatus === AccountStatus::Pending ? 'Resend portal invite' : 'Send portal invite' ?></button>
        </form>
    <?php endif ?>
    <?php if ($canEdit): ?>
        <a href="<?= e(url('staff.visitors.edit', ['ref' => $ref])) ?>" class="btn btn-brand"><?= icon('pencil', 'size-4') ?> Edit</a>
    <?php endif ?>
<?php $this->stop() ?>

<?php if ($kyc === KycStatus::Rejected && !empty($customer['kyc_remarks'])): ?>
    <?= $this->component('alert', ['tone' => 'danger', 'class' => 'mb-6', 'title' => 'KYC rejected', 'message' => (string) $customer['kyc_remarks']]) ?>
<?php endif ?>

<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
    <div class="min-w-0 space-y-6">
        <?= $this->partial('partials/visitor/summary', ['editBase' => null]) ?>
        <section>
            <div class="mb-4 flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">Documents</h2>
                    <p class="text-sm text-muted"><?= $canUpload ? 'Upload scans, or capture with the webcam.' : (ProfileService::documentsLocked($customer) ? 'Locked after KYC verification.' : 'View only.') ?></p>
                </div>
            </div>
            <?= $this->partial('partials/visitor/documents', ['context' => 'staff', 'ref' => $ref, 'locked' => !$canUpload, 'webcam' => true, 'canView' => $canViewDocs, 'gridClass' => 'md:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2']) ?>
        </section>
    </div>

    <aside class="space-y-6">
        <?= $this->partial('partials/visitor/id-card', ['compact' => true]) ?>

        <section class="card card-body">
            <h2 class="text-base font-bold">KYC</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex items-center justify-between gap-3"><dt class="text-muted">Status</dt><dd><?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Submitted</dt><dd class="font-medium"><?= e(format_date($customer['kyc_submitted_at'] ?? null, 'd M Y, g:i a')) ?: '—' ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Verified</dt><dd class="text-right font-medium"><?= $customer['kyc_verified_at'] !== null ? e(format_date((string) $customer['kyc_verified_at'], 'd M Y')) . ' · ' . e($verifiedBy ?? '') : '—' ?></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Registered by</dt><dd class="font-medium"><?= e($registeredBy ?? 'Self (online)') ?></dd></div>
            </dl>
        </section>

        <section class="card card-body">
            <h2 class="text-base font-bold">Portal account</h2>
            <?php if ($account !== null): ?>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Email</dt><dd class="truncate font-medium"><?= e($account['email']) ?></dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-muted">Status</dt><dd><?= $this->component('badge', ['label' => $accountStatus?->label() ?? '', 'tone' => $accountStatus?->tone() ?? 'neutral']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Last sign-in</dt><dd class="font-medium"><?= e(format_date($account['last_login_at'] ?? null, 'd M Y, g:i a')) ?: 'Never' ?></dd></div>
                </dl>
            <?php else: ?>
                <p class="mt-2 text-sm text-muted">No online account. Send a portal invite so the visitor can view bookings and invoices online.</p>
            <?php endif ?>
        </section>

        <section class="card card-body">
            <h2 class="flex items-center gap-2 text-base font-bold"><?= icon('history', 'size-4 text-muted') ?> Activity</h2>
            <ol class="mt-4 space-y-3">
                <?php foreach ($history as $h): ?>
                    <li class="flex gap-3 text-sm">
                        <span class="mt-1.5 size-2 shrink-0 rounded-full <?= str_starts_with((string) $h['action'], 'kyc.') ? 'bg-accent-500' : 'bg-brand-300' ?>"></span>
                        <div class="min-w-0">
                            <p class="font-medium"><?= e($actionLabels[$h['action']] ?? $h['action']) ?></p>
                            <p class="text-xs text-muted"><?= e(format_date((string) $h['created_at'], 'd M Y, g:i a')) ?> · <?= e($h['staff_name'] ?? ($h['actor_type'] === 'account' ? 'Visitor' : 'System')) ?></p>
                            <?php if (!empty($h['reason'])): ?><p class="mt-0.5 text-xs text-ink/70">“<?= e($h['reason']) ?>”</p><?php endif ?>
                        </div>
                    </li>
                <?php endforeach ?>
                <?php if ($history === []): ?><li class="text-sm text-muted">No activity yet.</li><?php endif ?>
            </ol>
        </section>
    </aside>
</div>
