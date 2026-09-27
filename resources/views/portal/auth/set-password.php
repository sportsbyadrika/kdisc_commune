<?php
/**
 * Set / reset password from an emailed single-use link, with a live strength meter.
 *
 * @var App\Core\Template $this
 * @var App\Enums\TokenPurpose $purpose
 * @var string $email
 * @var string $name
 * @var string $action
 */
use App\Enums\TokenPurpose;

$reset = $purpose === TokenPurpose::Reset;
$this->layout('layouts/auth', [
    'panelEyebrow' => 'Visitor portal',
    'panelTitle' => $reset ? 'Back in a moment.' : 'One step to go.',
    'panelText' => $reset ? 'Choose a new password for your Commune account.' : 'Choose a strong password. Next, complete your profile and KYC to get your Unique Visitor ID.',
]);
$first = $name !== '' ? explode(' ', $name)[0] : '';
?>
<div>
    <?= $this->component('badge', ['label' => $reset ? 'Password reset' : ($purpose === TokenPurpose::Invite ? 'Portal invite' : 'Step 2 of 3 · Set password'), 'tone' => 'brand', 'icon' => 'key-round']) ?>
    <h1 class="mt-4 text-3xl font-extrabold"><?= $reset ? 'Choose a new password' : 'Hi' . ($first !== '' ? ' ' . e($first) : '') . ', set your password' ?></h1>
    <p class="mt-2 text-muted"><?= $reset ? 'For' : 'Your email' ?> <span class="font-semibold text-ink"><?= e($email) ?></span><?= $reset ? '' : ' is confirmed once you save.' ?></p>

    <form method="post" action="<?= e($action) ?>" class="mt-8 space-y-5" x-data="passwordStrength" novalidate>
        <?= csrf_field() ?>
        <input type="email" name="username" value="<?= e($email) ?>" autocomplete="username" class="hidden" readonly tabindex="-1" aria-hidden="true">
        <div>
            <?= $this->component('input', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'icon' => 'lock', 'attrs' => ['x-model' => 'pw', 'autofocus' => true]]) ?>
            <div class="mt-3" aria-live="polite">
                <div class="flex gap-1.5" aria-hidden="true">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <span class="h-1.5 flex-1 rounded-full bg-surface-2 transition-colors duration-300"
                              :class="score >= <?= $i ?> && (score === 1 ? '!bg-red-500' : score === 2 ? '!bg-amber-500' : score === 3 ? '!bg-sky-500' : '!bg-emerald-500')"></span>
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
        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="!valid || !matches || confirm === ''">
            <?= $reset ? 'Save new password' : 'Save & continue' ?> <?= icon('arrow-right', 'size-4') ?>
        </button>
    </form>
</div>
