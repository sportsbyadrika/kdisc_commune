<?php
/**
 * Expired / used / unknown password link.
 *
 * @var App\Core\Template $this
 * @var string $reason expired|used|invalid
 */
$this->layout('layouts/auth', ['panelEyebrow' => 'Visitor portal', 'panelTitle' => 'Let’s get you a fresh link.', 'panelText' => 'For your security, password links work once and expire after an hour.']);
$copy = [
    'expired' => ['This link has expired', 'Password links are valid for a limited time. Enter your email and we’ll send a new one.'],
    'used' => ['This link was already used', 'Each link works only once. If you already set your password, just sign in — or request a new link below.'],
    'invalid' => ['This link is not valid', 'The link may be incomplete — try copying the full link from the email, or request a new one below.'],
][$reason] ?? ['This link is not valid', 'Request a new link below.'];
?>
<div>
    <span class="inline-grid size-16 place-items-center rounded-2xl bg-amber-50 text-amber-600"><?= icon('hourglass', 'size-8') ?></span>
    <h1 class="mt-6 text-3xl font-extrabold"><?= e($copy[0]) ?></h1>
    <p class="mt-3 text-muted"><?= e($copy[1]) ?></p>
    <form method="post" action="<?= e(url('portal.password.email')) ?>" class="mt-8 space-y-4">
        <?= csrf_field() ?>
        <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'icon' => 'mail', 'autocomplete' => 'email']) ?>
        <button type="submit" class="btn btn-primary btn-lg w-full"><?= icon('send', 'size-4') ?> Send a new link</button>
    </form>
    <p class="mt-8 text-center text-sm text-muted"><a href="<?= e(url('portal.login')) ?>" class="font-semibold text-brand-600 hover:underline">Go to sign in</a></p>
</div>
