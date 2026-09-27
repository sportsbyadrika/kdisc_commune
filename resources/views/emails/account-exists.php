<?php
/**
 * @var App\Core\Template $this
 * @var string $loginUrl
 * @var string $resetUrl
 */
$this->layout('emails/layout', ['preheader' => 'Someone tried to register with this email address.']);
$t = (array) config('mail.theme', []);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">You already have an account</h1>
<p style="margin:0;">Someone (hopefully you) tried to register a new Commune account with this email address. You already have one — just sign in.</p>
<?= $this->partial('emails/button', ['url' => $loginUrl, 'label' => 'Sign in']) ?>
<p style="margin:0;font-size:13px;color:<?= e($t['muted'] ?? '') ?>;">Forgot your password? <a href="<?= e($resetUrl) ?>" style="color:<?= e($t['brand'] ?? '') ?>;">Reset it here</a>. If this wasn’t you, no action is needed.</p>
