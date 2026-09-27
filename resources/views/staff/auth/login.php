<?php
/**
 * Staff sign-in.
 *
 * @var App\Core\Template $this
 * @var string|null $captchaQuestion set after repeated failed attempts (LoginCaptcha)
 */
$this->layout('layouts/auth', [
    'panelEyebrow' => 'Staff console',
    'panelTitle' => 'Run the centre, beautifully.',
    'panelText' => 'Registrations, bookings, check-ins, payments and GST invoices — all in one place.',
    'panelImage' => 'media/floor-ground.svg',
]);
?>
<div>
    <?= $this->component('badge', ['label' => 'Staff console', 'tone' => 'brand', 'icon' => 'shield-check']) ?>
    <h1 class="mt-4 text-3xl font-extrabold">Sign in</h1>
    <p class="mt-2 text-muted">Use your K-DISC staff account.</p>

    <form method="post" action="<?= e(url('staff.login.attempt')) ?>" class="mt-8 space-y-5" novalidate>
        <?= csrf_field() ?>
        <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'username', 'icon' => 'mail', 'placeholder' => 'you@kdisc.kerala.gov.in', 'attrs' => ['autofocus' => true]]) ?>
        <div>
            <?= $this->component('input', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'icon' => 'lock']) ?>
            <p class="mt-2 text-right text-sm"><a href="<?= e(url('staff.password.forgot')) ?>" class="font-semibold text-brand-700 hover:underline" data-test="staff-forgot">Forgot password?</a></p>
        </div>
        <?php if (!empty($captchaQuestion)): ?>
            <?= $this->partial('partials/auth/captcha', ['question' => $captchaQuestion, 'note' => 'Several sign-in attempts failed — please answer to continue.']) ?>
        <?php endif ?>
        <?= $this->component('button', ['label' => 'Sign in', 'type' => 'submit', 'variant' => 'brand', 'size' => 'lg', 'class' => 'w-full', 'iconRight' => 'arrow-right']) ?>
    </form>
    <p class="mt-8 text-center text-sm text-muted">New staff member? Your Centre Manager adds you and you get an email to set your password.</p>
    <p class="mt-2 text-center text-sm"><a href="<?= e(url('home')) ?>" class="font-semibold text-brand-700 hover:underline">← Back to the public site</a></p>
</div>
