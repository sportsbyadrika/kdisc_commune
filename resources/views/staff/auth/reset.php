<?php
/**
 * Staff invite (first password) or password reset from an emailed single-use link, with the strength meter.
 *
 * @var App\Core\Template $this
 * @var bool $invite
 * @var string $email
 * @var string $name
 * @var string $action
 */
$this->layout('layouts/auth', [
    'panelEyebrow' => 'Staff console',
    'panelTitle' => $invite ? 'Welcome to the team.' : 'Back in a moment.',
    'panelText' => $invite ? 'Choose a strong password for your Commune staff account.' : 'Choose a new password for your staff account. Other signed-in browsers are signed out.',
    'panelImage' => 'media/floor-ground.svg',
]);
$first = $name !== '' ? explode(' ', $name)[0] : '';
?>
<div>
    <?= $this->component('badge', ['label' => $invite ? 'Staff invite' : 'Password reset', 'tone' => 'brand', 'icon' => 'key-round']) ?>
    <h1 class="mt-4 text-3xl font-extrabold"><?= $invite ? 'Hi' . ($first !== '' ? ' ' . e($first) : '') . ', set your password' : 'Choose a new password' ?></h1>
    <p class="mt-2 text-muted">For <span class="font-semibold text-ink"><?= e($email) ?></span></p>

    <form method="post" action="<?= e($action) ?>" class="mt-8 space-y-5" x-data="passwordStrength" novalidate>
        <?= csrf_field() ?>
        <input type="email" name="username" value="<?= e($email) ?>" autocomplete="username" class="hidden" readonly tabindex="-1" aria-hidden="true">
        <div>
            <?= $this->component('input', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'icon' => 'lock', 'attrs' => ['x-model' => 'pw', 'autofocus' => true, 'aria-describedby' => 'pw-strength']]) ?>
            <div class="mt-3" id="pw-strength" aria-live="polite">
                <div class="flex gap-1.5" aria-hidden="true">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <span class="h-1.5 flex-1 rounded-full bg-surface-2 transition-colors duration-300" :class="barClass(<?= $i ?>)"></span>
                    <?php endfor ?>
                </div>
                <p class="mt-1.5 flex justify-between text-xs"><span class="text-muted">Password strength</span><span class="font-semibold" x-text="label"></span></p>
                <ul class="mt-3 grid grid-cols-1 gap-1.5 text-xs sm:grid-cols-2">
                    <template x-for="c in checks" :key="c.label">
                        <li class="flex items-center gap-1.5" :class="c.ok ? 'text-emerald-700' : 'text-muted'">
                            <span x-show="c.ok"><?= icon('circle-check', 'size-3.5') ?></span><span x-show="!c.ok"><?= icon('circle-dashed', 'size-3.5') ?></span>
                            <span x-text="c.label"></span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
        <div>
            <?= $this->component('input', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'icon' => 'lock', 'attrs' => ['x-model' => 'confirm']]) ?>
            <p x-cloak x-show="!matches" class="error-text"><?= icon('circle-alert', 'size-3.5') ?>The passwords do not match yet.</p>
        </div>
        <button type="submit" class="btn btn-brand btn-lg w-full" :disabled="!canSubmit">
            <?= $invite ? 'Save password' : 'Save new password' ?> <?= icon('arrow-right', 'size-4') ?>
        </button>
    </form>
</div>
