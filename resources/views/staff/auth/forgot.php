<?php
/** @var App\Core\Template $this */
$this->layout('layouts/auth', [
    'panelEyebrow' => 'Staff console',
    'panelTitle' => 'Happens to everyone.',
    'panelText' => 'We’ll email a single-use link to the address on your staff account.',
    'panelImage' => 'media/floor-ground.svg',
]);
?>
<div>
    <?= $this->component('badge', ['label' => 'Forgot password', 'tone' => 'brand', 'icon' => 'key-round']) ?>
    <h1 class="mt-4 text-3xl font-extrabold">Reset your staff password</h1>
    <p class="mt-2 text-muted">Enter your staff email. If it belongs to an active account, we’ll send a reset link. Locked out or no longer have access to that inbox? Ask your Centre Manager to update your email and send a new link.</p>
    <form method="post" action="<?= e(url('staff.password.email')) ?>" class="mt-8 space-y-5" novalidate>
        <?= csrf_field() ?>
        <?= $this->component('input', ['name' => 'email', 'label' => 'Staff email', 'type' => 'email', 'required' => true, 'icon' => 'mail', 'autocomplete' => 'email', 'attrs' => ['autofocus' => true]]) ?>
        <?= $this->component('button', ['label' => 'Email me a link', 'type' => 'submit', 'variant' => 'brand', 'size' => 'lg', 'class' => 'w-full', 'icon' => 'send']) ?>
    </form>
    <p class="mt-8 text-center text-sm text-muted"><a href="<?= e(url('staff.login')) ?>" class="font-semibold text-brand-700 hover:underline">← Back to sign in</a></p>
</div>
