<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var string|null $uniqueId
 * @var string|null $portalUrl
 * @var string|null $remarks
 */
$this->layout('emails/layout', ['preheader' => 'Your KYC is verified — you can now confirm bookings.']);
$t = (array) config('mail.theme', []);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Your KYC is verified ✓</h1>
<p style="margin:0;">Good news<?= $name !== '' ? ', ' . e(explode(' ', $name)[0]) : '' ?> — your identity documents have been verified. You can now confirm seat bookings at Commune Kottarakara.</p>
<?php if (!empty($uniqueId)): ?><?= $this->partial('emails/id-badge', ['uniqueId' => $uniqueId]) ?><?php endif ?>
<?php if (!empty($remarks)): ?><p style="margin:0 0 12px;padding:12px 16px;background:<?= e($t['surface'] ?? '') ?>;border-radius:12px;"><strong>Note from the centre:</strong> <?= e($remarks) ?></p><?php endif ?>
<?php if (!empty($portalUrl)): ?><?= $this->partial('emails/button', ['url' => $portalUrl, 'label' => 'Open my dashboard']) ?><?php endif ?>
