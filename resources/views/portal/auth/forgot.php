<?php
/** @var App\Core\Template $this */
$this->layout('layouts/auth', ['panelEyebrow' => 'Visitor portal', 'panelTitle' => 'Happens to everyone.', 'panelText' => 'We’ll email you a single-use link to choose a new password.']);
?>
<div>
    <?= $this->component('badge', ['label' => 'Forgot password', 'tone' => 'brand', 'icon' => 'key-round']) ?>
    <h1 class="mt-4 text-3xl font-extrabold">Reset your password</h1>
    <p class="mt-2 text-muted">Enter the email you registered with. If it matches an account, we’ll send a reset link. Never activated your account? This sends a fresh set-password link too.</p>
    <form method="post" action="<?= e(url('portal.password.email')) ?>" class="mt-8 space-y-5" novalidate>
        <?= csrf_field() ?>
        <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'icon' => 'mail', 'autocomplete' => 'email', 'attrs' => ['autofocus' => true]]) ?>
        <?= $this->component('button', ['label' => 'Email me a link', 'type' => 'submit', 'variant' => 'primary', 'size' => 'lg', 'class' => 'w-full', 'icon' => 'send']) ?>
    </form>
    <p class="mt-8 text-center text-sm text-muted"><a href="<?= e(url('portal.login')) ?>" class="font-semibold text-brand-600 hover:underline">← Back to sign in</a></p>
</div>
