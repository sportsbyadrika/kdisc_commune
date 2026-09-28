<?php
/**
 * Expired / used / unknown staff password link.
 *
 * @var App\Core\Template $this
 * @var string $reason expired|used|invalid
 */
$this->layout('layouts/auth', ['panelEyebrow' => 'Staff console', 'panelTitle' => 'Let’s get you a fresh link.', 'panelText' => 'For your security, password links work once and expire.', 'panelImage' => 'media/floor-ground.svg']);
$copy = [
    'expired' => ['This link has expired', 'Password links are valid for a limited time. Request a new one below, or ask your Centre Manager to resend your invite.'],
    'used' => ['This link was already used', 'Each link works only once. If you already set your password, just sign in.'],
    'invalid' => ['This link is not valid', 'The link may be incomplete — copy the full link from the email, or request a new one.'],
][$reason] ?? ['This link is not valid', 'Request a new link below.'];
?>
<div>
    <span class="inline-grid size-16 place-items-center rounded-2xl bg-amber-50 text-amber-700"><?= icon('hourglass', 'size-8') ?></span>
    <h1 class="mt-6 text-3xl font-extrabold"><?= e($copy[0]) ?></h1>
    <p class="mt-3 text-muted"><?= e($copy[1]) ?></p>
    <div class="mt-8 flex flex-wrap gap-3">
        <a href="<?= e(url('staff.password.forgot')) ?>" class="btn btn-brand"><?= icon('send', 'size-4') ?> Request a new link</a>
        <a href="<?= e(url('staff.login')) ?>" class="btn btn-outline">Go to sign in</a>
    </div>
</div>
