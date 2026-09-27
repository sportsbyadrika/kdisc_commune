<?php
/**
 * Step 2 fields — identity & KYC (spec 4.3). Shared by the portal wizard and the staff form.
 * Field names match ProfileService::identityRules().
 *
 * @var App\Core\Template $this
 * @var App\Enums\CustomerType $type
 * @var array<string, mixed> $customer
 * @var array<string, mixed>|null $signatory
 */
use App\Enums\CustomerType;
use App\Models\Customer;

$c = $customer;
$individual = $type === CustomerType::Individual;
$foreign = (string) old('nationality_type', ($c['id'] ?? 0) && Customer::isForeign($c) ? 'foreign' : 'indian') === 'foreign';
$country = (string) old('country', $foreign && Customer::isForeign($c) ? (string) ($c['nationality'] ?? '') : '');
$consentText = 'I consent to K-DISC collecting and storing this Aadhaar number, encrypted, only to verify identity for workspace services. It is never shared or displayed in full.';
?>
<?php if ($individual): ?>
<div class="space-y-6" x-data="{ nat: '<?= $foreign ? 'foreign' : 'indian' ?>' }">
    <fieldset>
        <legend class="label">Nationality <span class="text-accent-500" aria-hidden="true">*</span></legend>
        <div class="grid gap-3 sm:grid-cols-2">
            <?php foreach ([['indian', 'Indian citizen', 'Aadhaar is mandatory'], ['foreign', 'Foreign national', 'Passport instead of Aadhaar']] as [$v, $l, $s]): ?>
                <label class="flex cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 py-3 transition" :class="nat === '<?= $v ?>' ? 'border-brand-600 bg-brand-50/60' : 'border-line hover:border-ink/25'">
                    <input type="radio" name="nationality_type" value="<?= $v ?>" x-model="nat" class="size-4 accent-brand-600" <?= ($foreign ? 'foreign' : 'indian') === $v ? 'checked' : '' ?>>
                    <span><span class="block text-sm font-bold"><?= e($l) ?></span><span class="block text-xs text-muted"><?= e($s) ?></span></span>
                </label>
            <?php endforeach ?>
        </div>
    </fieldset>

    <div x-show="nat === 'indian'" class="space-y-4">
        <?= $this->partial('partials/visitor/aadhaar-field', ['name' => 'aadhaar', 'label' => 'Aadhaar number', 'last4' => $c['aadhaar_last4'] ?? null]) ?>
        <?= $this->component('checkbox', ['name' => 'aadhaar_consent', 'label' => 'Aadhaar consent', 'help' => $consentText, 'checked' => !empty($c['consent_at'])]) ?>
    </div>

    <div x-cloak x-show="nat === 'foreign'" class="grid gap-5 sm:grid-cols-2">
        <?= $this->component('input', ['name' => 'country', 'label' => 'Nationality (country)', 'value' => $country, 'required' => true, 'icon' => 'globe', 'placeholder' => 'e.g. Germany']) ?>
        <div x-data="kycCheck('passport')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
            <?= $this->component('input', ['name' => 'passport_no', 'label' => 'Passport number', 'value' => $c['passport_no'] ?? '', 'required' => true, 'icon' => 'book-open-text', 'attrs' => ['autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
            <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
        </div>
    </div>

    <div class="grid gap-5 border-t border-line pt-6 sm:grid-cols-2">
        <div x-data="kycCheck('pan')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
            <?= $this->component('input', ['name' => 'pan', 'label' => 'PAN (optional)', 'value' => $c['pan'] ?? '', 'icon' => 'id-card', 'placeholder' => 'ABCPE1234F', 'help' => 'Needed for GST invoices in your name.', 'attrs' => ['maxlength' => 10, 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
            <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
        </div>
        <div x-data="kycCheck('gstin', { pan: 'input[name=pan]', state: 'select[name=state_code], input[name=state_code]' })" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
            <?= $this->component('input', ['name' => 'gstin', 'label' => 'GSTIN (optional)', 'value' => $c['gstin'] ?? '', 'icon' => 'receipt', 'placeholder' => '32ABCPE1234F1ZK', 'help' => 'If you are GST-registered (must contain your PAN).', 'attrs' => ['maxlength' => 15, 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
            <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="space-y-8">
    <div>
        <h3 class="text-base font-bold">Tax registrations</h3>
        <p class="mt-1 text-sm text-muted">The GSTIN must contain the institution’s PAN and match the state of the registered address.</p>
        <div class="mt-4 grid gap-5 sm:grid-cols-3">
            <div x-data="kycCheck('pan')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
                <?= $this->component('input', ['name' => 'pan', 'label' => 'PAN', 'value' => $c['pan'] ?? '', 'required' => true, 'placeholder' => 'AABCK1234L', 'attrs' => ['maxlength' => 10, 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
                <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
            </div>
            <div x-data="kycCheck('gstin', { pan: 'input[name=pan]', state: 'select[name=state_code], input[name=state_code]' })" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
                <?= $this->component('input', ['name' => 'gstin', 'label' => 'GSTIN', 'value' => $c['gstin'] ?? '', 'required' => true, 'placeholder' => '32AABCK1234L1ZV', 'attrs' => ['maxlength' => 15, 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
                <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
            </div>
            <div x-data="kycCheck('tan')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
                <?= $this->component('input', ['name' => 'tan', 'label' => 'TAN', 'value' => $c['tan'] ?? '', 'required' => true, 'placeholder' => 'TVDK12345E', 'attrs' => ['maxlength' => 10, 'autocapitalize' => 'characters', 'spellcheck' => 'false']]) ?>
                <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
            </div>
        </div>
    </div>
    <div class="border-t border-line pt-6">
        <h3 class="text-base font-bold">Authorised signatory / contact person</h3>
        <p class="mt-1 text-sm text-muted">The person who signs agreements for the institution. Their Aadhaar is mandatory.</p>
        <?php $s = $signatory ?? []; ?>
        <div class="mt-4 grid gap-5 sm:grid-cols-2">
            <?= $this->component('input', ['name' => 'sig_name', 'label' => 'Full name', 'value' => $s['name'] ?? '', 'required' => true, 'icon' => 'user']) ?>
            <?= $this->component('input', ['name' => 'sig_designation', 'label' => 'Designation', 'value' => $s['designation'] ?? '', 'required' => true, 'icon' => 'briefcase', 'placeholder' => 'e.g. Director, Registrar']) ?>
            <?= $this->component('input', ['name' => 'sig_email', 'label' => 'Email', 'type' => 'email', 'value' => $s['email'] ?? '', 'required' => true, 'icon' => 'mail']) ?>
            <div x-data="kycCheck('mobile')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
                <?= $this->component('input', ['name' => 'sig_mobile', 'label' => 'Mobile', 'type' => 'tel', 'value' => format_phone($s['mobile'] ?? null), 'required' => true, 'icon' => 'phone', 'placeholder' => '+91 98765 43210']) ?>
                <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
            </div>
            <div class="sm:col-span-2 space-y-4">
                <?= $this->partial('partials/visitor/aadhaar-field', ['name' => 'sig_aadhaar', 'label' => "Signatory's Aadhaar number", 'last4' => $s['aadhaar_last4'] ?? null]) ?>
                <?= $this->component('checkbox', ['name' => 'aadhaar_consent', 'label' => 'Aadhaar consent (from the signatory)', 'help' => 'The signatory consents to K-DISC collecting and storing their Aadhaar number, encrypted, only to verify identity for workspace services.', 'checked' => !empty($c['consent_at'])]) ?>
            </div>
        </div>
    </div>
</div>
<?php endif ?>
