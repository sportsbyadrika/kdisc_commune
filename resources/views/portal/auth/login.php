<?php
/** @var App\Core\Template $this */
$this->layout('layouts/auth', [
    'panelEyebrow' => 'Visitor portal',
    'panelTitle' => 'Welcome back.',
    'panelText' => 'Track your KYC, bookings, dues and invoices — all in one place.',
]);
?>
<div>
    <?= $this->component('badge', ['label' => 'Visitor portal', 'tone' => 'accent', 'icon' => 'circle-user-round']) ?>
    <h1 class="mt-4 text-3xl font-extrabold">Sign in</h1>
    <p class="mt-2 text-muted">Individuals and institutions registered with Commune.</p>

    <form method="post" action="<?= e(url('portal.login.attempt')) ?>" class="mt-8 space-y-5" novalidate>
        <?= csrf_field() ?>
        <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'username', 'icon' => 'mail', 'placeholder' => 'you@example.com', 'attrs' => ['autofocus' => true]]) ?>
        <div>
            <?= $this->component('input', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'icon' => 'lock']) ?>
            <p class="mt-2 text-right text-sm"><a href="<?= e(url('portal.password.forgot')) ?>" class="font-semibold text-brand-600 hover:underline">Forgot password?</a></p>
        </div>
        <?= $this->component('button', ['label' => 'Sign in', 'type' => 'submit', 'variant' => 'primary', 'size' => 'lg', 'class' => 'w-full', 'iconRight' => 'arrow-right']) ?>
    </form>

    <div class="mt-8 rounded-2xl border border-line bg-surface p-5 text-sm">
        <p class="font-semibold">New to Commune?</p>
        <p class="mt-1 text-muted">Create an account in a minute, then complete your KYC online.</p>
        <a href="<?= e(url('portal.register')) ?>" class="btn btn-outline mt-4 w-full"><?= icon('user-plus', 'size-4') ?> Create an account</a>
    </div>
    <p class="mt-6 text-center text-xs text-muted">Registered at the front desk? <a href="<?= e(url('portal.register')) ?>" class="font-semibold text-brand-600 hover:underline">Create an account</a> with the same email to link your profile.</p>
    <p class="mt-2 text-center text-xs text-muted">Staff member? <a href="<?= e(url('staff.login')) ?>" class="font-semibold text-brand-600 hover:underline">Staff console</a></p>
</div>
