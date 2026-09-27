<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var string $url
 * @var int $minutes
 * @var string|null $uniqueId
 */
$this->layout('emails/layout', ['preheader' => 'Your front-desk registration is ready — set a password to use the visitor portal.']);
$t = (array) config('mail.theme', []);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">You’re registered at Commune</h1>
<p style="margin:0 0 12px;">Hi<?= $name !== '' ? ' ' . e(explode(' ', $name)[0]) : '' ?>, our front desk has registered you at Commune Kottarakara.</p>
<?php if (!empty($uniqueId)): ?><?= $this->partial('emails/id-badge', ['uniqueId' => $uniqueId]) ?><?php endif ?>
<p style="margin:0;">Set a password to use the visitor portal — see your KYC status, bookings, dues and invoices online.</p>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'Activate portal access']) ?>
<p style="margin:0;font-size:13px;color:<?= e($t['muted'] ?? '') ?>;">This link works once and expires in <?= (int) $minutes ?> minutes. Ask the front desk for a new invite, or use “Forgot password” on the sign-in page.</p>
