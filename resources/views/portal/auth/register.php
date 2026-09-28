<?php
/**
 * Visitor self-registration (spec 4.1 step 1).
 *
 * @var App\Core\Template $this
 * @var string $captchaQuestion
 * @var App\Enums\CustomerType $defaultType
 */
use App\Enums\CustomerType;
use App\Services\Auth\Captcha;

$this->layout('layouts/auth', [
    'panelEyebrow' => 'Visitor portal',
    'panelTitle' => 'Your seat, near home.',
    'panelText' => 'Register once, verify your KYC online and book flexi desks, dedicated seats, cabins or the conference room.',
]);
$type = (string) old('type', $defaultType->value);
?>
<div x-data="{ type: '<?= e($type === 'institution' ? 'institution' : 'individual') ?>' }">
    <?= $this->component('badge', ['label' => 'Step 1 of 3 · Create account', 'tone' => 'brand', 'icon' => 'user-plus']) ?>
    <h1 class="mt-4 text-3xl font-extrabold">Create your account</h1>
    <p class="mt-2 text-muted">We’ll email you a link to set your password. It takes a minute.</p>

    <form method="post" action="<?= e(url('portal.register.store')) ?>" class="mt-8 space-y-5" novalidate>
        <?= csrf_field() ?>
        <fieldset>
            <legend class="label">I am registering as <span class="text-accent-500" aria-hidden="true">*</span></legend>
            <div class="grid grid-cols-2 gap-3">
                <?php foreach ([['individual', 'user-round', 'An individual', 'Remote worker, freelancer, student…'], ['institution', 'building-2', 'An institution', 'Company, startup, NGO, govt. body…']] as [$val, $ico, $lbl, $sub]): ?>
                    <label class="relative flex cursor-pointer flex-col gap-1 rounded-2xl border-2 p-4 transition"
                           :class="type === '<?= $val ?>' ? 'border-brand-600 bg-brand-50/60 shadow-sm' : 'border-line bg-white hover:border-ink/25'">
                        <input type="radio" name="type" value="<?= $val ?>" x-model="type" class="sr-only" <?= $type === $val ? 'checked' : '' ?>>
                        <span class="flex items-center justify-between">
                            <span class="grid size-9 place-items-center rounded-xl" :class="type === '<?= $val ?>' ? 'bg-brand-600 text-white' : 'bg-surface text-ink/70'"><?= icon($ico, 'size-5') ?></span>
                            <span x-cloak x-show="type === '<?= $val ?>'" class="text-brand-600"><?= icon('circle-check', 'size-5') ?></span>
                        </span>
                        <span class="mt-2 text-sm font-bold"><?= e($lbl) ?></span>
                        <span class="text-xs text-muted"><?= e($sub) ?></span>
                    </label>
                <?php endforeach ?>
            </div>
            <?php if (errors('type')): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e(errors('type')) ?></p><?php endif ?>
        </fieldset>

        <div>
            <?= $this->component('input', ['name' => 'name', 'label' => 'Full name', 'required' => true, 'autocomplete' => 'name', 'icon' => 'user', 'attrs' => [':placeholder' => "type === 'institution' ? 'Contact person, e.g. Anjali Nair' : 'As on your ID, e.g. Anjali Nair'"]]) ?>
            <p x-cloak x-show="type === 'institution'" class="help">Your own name as the contact person — institution details come next.</p>
        </div>
        <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'icon' => 'mail', 'placeholder' => 'you@example.com', 'help' => 'This will be your sign-in. We’ll send the set-password link here.']) ?>
        <div x-data="kycCheck('mobile')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
            <?= $this->component('input', ['name' => 'mobile', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'autocomplete' => 'tel', 'icon' => 'phone', 'placeholder' => '+91 98765 43210', 'attrs' => ['inputmode' => 'tel']]) ?>
            <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
        </div>

        <!-- Honeypot: humans never see or fill this. -->
        <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
            <label for="f-website">Website</label>
            <input id="f-website" type="text" name="<?= e(Captcha::HONEYPOT) ?>" tabindex="-1" autocomplete="off">
        </div>

        <?= $this->partial('partials/auth/captcha', ['question' => $captchaQuestion]) ?>

        <div>
            <label for="f-consent" class="flex cursor-pointer items-start gap-3 text-sm">
                <input id="f-consent" type="checkbox" name="consent" value="1" <?= old('consent') ? 'checked' : '' ?> class="mt-0.5 size-4.5 rounded border-line accent-brand-600">
                <span class="text-ink/80">I agree to the <a href="<?= e(url('terms')) ?>" target="_blank" class="font-semibold text-brand-600 hover:underline">terms of use</a> and the <a href="<?= e(url('privacy')) ?>" target="_blank" class="font-semibold text-brand-600 hover:underline">privacy policy</a>, and consent to K-DISC processing my details to provide workspace services (DPDP Act 2023).</span>
            </label>
            <?php if (errors('consent')): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e(errors('consent')) ?></p><?php endif ?>
        </div>

        <?= $this->component('button', ['label' => 'Create account', 'type' => 'submit', 'variant' => 'primary', 'size' => 'lg', 'class' => 'w-full', 'iconRight' => 'arrow-right']) ?>
    </form>

    <p class="mt-8 text-center text-sm text-muted">Already registered? <a href="<?= e(url('portal.login')) ?>" class="font-semibold text-brand-600 hover:underline">Sign in</a></p>
    <p class="mt-2 text-center text-xs text-muted">Prefer help in person? Our front desk can register you — <a href="<?= e(url('contact')) ?>" class="underline">contact us</a>.</p>
</div>
