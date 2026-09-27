<?php
/**
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var list<array<string, mixed>> $checklist
 * @var bool $locked
 */
$this->layout('layouts/portal', ['heading' => 'My documents', 'subheading' => 'KYC proofs on file. Stored privately — only you and our KYC team can open them.']);
?>
<?php if ($locked): ?>
    <?= $this->component('alert', ['tone' => 'success', 'class' => 'mb-6', 'title' => 'KYC verified', 'message' => 'Documents are locked after verification. To replace one, edit your profile (it goes back for re-verification) or ask the front desk.']) ?>
<?php else: ?>
    <?= $this->component('alert', ['tone' => 'info', 'class' => 'mb-6', 'message' => 'Tip: upload a masked Aadhaar (only the last 4 digits visible). Photos are re-saved without location data.']) ?>
<?php endif ?>
<?= $this->partial('partials/visitor/documents', ['context' => 'portal', 'locked' => $locked]) ?>
