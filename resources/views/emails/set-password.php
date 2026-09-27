<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var string $url
 * @var int $minutes
 */
$this->layout('emails/layout', ['preheader' => 'Confirm your email and choose a password — the link works once.']);
$t = (array) config('mail.theme', []);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;color:<?= e($t['ink'] ?? '') ?>;">Welcome to Commune<?= $name !== '' ? ', ' . e(explode(' ', $name)[0]) : '' ?>!</h1>
<p style="margin:0 0 12px;">Thanks for registering with Commune Kottarakara — workspaces near home by K-DISC.</p>
<p style="margin:0;">Click the button to confirm your email address and choose a password. Then you can complete your profile and KYC and start booking seats.</p>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'Set your password']) ?>
<p style="margin:0;font-size:13px;color:<?= e($t['muted'] ?? '') ?>;">This link works once and expires in <?= (int) $minutes ?> minutes. If it has expired, use “Resend link” on the sign-in page. If you did not register, you can ignore this email.</p>
