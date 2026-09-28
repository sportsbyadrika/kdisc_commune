<?php
/**
 * Staff invite (first password) or password reset link — StaffUserService::sendLink().
 *
 * @var App\Core\Template $this
 * @var string $name
 * @var string $role
 * @var bool $invite
 * @var string $url
 * @var int $minutes
 * @var string|null $by manager who sent it (null = requested on the sign-in page)
 */
$this->layout('emails/layout', ['preheader' => $invite ? 'Set a password to start using the Commune staff console.' : 'Choose a new password for your Commune staff account.']);
$t = (array) config('mail.theme', []);
$first = $name !== '' ? explode(' ', $name)[0] : '';
$validity = $minutes >= 120 ? intdiv($minutes, 60) . ' hours' : $minutes . ' minutes';
?>
<?php if ($invite): ?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Welcome to the Commune staff console</h1>
<p style="margin:0 0 12px;">Hi<?= $first !== '' ? ' ' . e($first) : '' ?>, <?= $by !== null ? e($by) . ' has added you' : 'you have been added' ?> as <strong><?= e($role) ?></strong> at Commune Kottarakara.</p>
<p style="margin:0;">Set a password to sign in:</p>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'Set my password']) ?>
<?php else: ?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Reset your staff password</h1>
<p style="margin:0;">Hi<?= $first !== '' ? ' ' . e($first) : '' ?>, <?= $by !== null ? e($by) . ' sent you a link to reset' : 'we received a request to reset' ?> the password of your Commune staff account.</p>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'Choose a new password']) ?>
<?php endif ?>
<p style="margin:0;font-size:13px;color:<?= e($t['muted'] ?? '') ?>;">The link works once and expires in <?= e($validity) ?>. If you did not expect this email, ignore it and tell your Centre Manager.</p>
