<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var string $url
 * @var int $minutes
 */
$this->layout('emails/layout', ['preheader' => 'Choose a new password for your Commune account.']);
$t = (array) config('mail.theme', []);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Reset your password</h1>
<p style="margin:0;">Hi<?= $name !== '' ? ' ' . e(explode(' ', $name)[0]) : '' ?>, we received a request to reset the password of your Commune visitor account.</p>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'Choose a new password']) ?>
<p style="margin:0;font-size:13px;color:<?= e($t['muted'] ?? '') ?>;">The link works once and expires in <?= (int) $minutes ?> minutes. If you did not ask for this, ignore this email — your password stays the same.</p>
