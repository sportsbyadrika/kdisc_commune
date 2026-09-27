<?php
/**
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var App\Enums\CustomerType $type
 * @var App\Enums\KycStatus $kyc
 * @var array<string, mixed>|null $signatory
 */
use App\Enums\KycStatus;

$this->layout('layouts/portal', ['heading' => 'My profile', 'subheading' => $type->label() . ' visitor']);
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-2"><?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
        <?php if ($kyc === KycStatus::Verified): ?><span class="text-sm text-muted">Verified on <?= e(format_date((string) $customer['kyc_verified_at'])) ?></span><?php endif ?>
    </div>
    <a href="<?= e(url('portal.wizard', ['step' => 1])) ?>" class="btn btn-brand"><?= icon('pencil', 'size-4') ?> Edit profile</a>
</div>
<?php if ($kyc === KycStatus::Verified): ?>
    <?= $this->component('alert', ['tone' => 'info', 'class' => 'mb-6', 'message' => 'Editing identity or contact details sends your profile back to “KYC pending” until the Centre Manager re-verifies it.']) ?>
<?php endif ?>
<?= $this->partial('partials/visitor/summary', ['editBase' => 'portal']) ?>
