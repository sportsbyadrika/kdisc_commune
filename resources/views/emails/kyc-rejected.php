<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var string $reason
 * @var string|null $wizardUrl
 */
$this->layout('emails/layout', ['preheader' => 'We could not verify your KYC yet — here is what to fix.']);
$t = (array) config('mail.theme', []);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">We need a little more from you</h1>
<p style="margin:0 0 16px;">Hi<?= $name !== '' ? ' ' . e(explode(' ', $name)[0]) : '' ?>, we could not verify your KYC yet. Our Centre Manager left this note:</p>
<p style="margin:0 0 16px;padding:14px 18px;background:<?= e($t['surface'] ?? '') ?>;border-left:4px solid <?= e($t['accent'] ?? '') ?>;border-radius:10px;"><?= e($reason) ?></p>
<?php if (!empty($wizardUrl)): ?>
<p style="margin:0;">Please update your details or documents and submit your profile again.</p>
<?= $this->partial('emails/button', ['url' => $wizardUrl, 'label' => 'Update my profile']) ?>
<?php else: ?>
<p style="margin:0;">Please visit the front desk with the original documents.</p>
<?php endif ?>
