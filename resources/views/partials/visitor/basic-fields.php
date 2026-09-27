<?php
/**
 * Step 1 fields (shared by the portal wizard and the staff form). Field names match ProfileService::basicRules().
 *
 * @var App\Core\Template $this
 * @var App\Enums\CustomerType $type
 * @var array<string, mixed> $customer
 * @var bool|null $emailLocked  portal: the sign-in email is not editable here
 */
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Support\IndianStates;

$individual = $type === CustomerType::Individual;
$c = $customer;
?>
<div class="grid gap-5 sm:grid-cols-2">
    <fieldset class="sm:col-span-2">
        <legend class="label"><?= $individual ? 'I am a' : 'Type of institution' ?> <span class="text-accent-500" aria-hidden="true">*</span></legend>
        <?php $current = (string) old('sub_category', $c['sub_category'] ?? ''); ?>
        <div class="flex flex-wrap gap-2">
            <?php foreach (CustomerSubCategory::optionsFor($type) as $val => $label): ?>
                <label class="cursor-pointer">
                    <input type="radio" name="sub_category" value="<?= e($val) ?>" class="peer sr-only" <?= $current === $val ? 'checked' : '' ?> required>
                    <span class="chip peer-checked:border-brand-900 peer-checked:bg-brand-900 peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:outline-brand-600"><?= e($label) ?></span>
                </label>
            <?php endforeach ?>
        </div>
        <?php if (errors('sub_category')): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e(errors('sub_category')) ?></p><?php endif ?>
    </fieldset>

    <?= $this->component('input', ['name' => 'name', 'label' => $individual ? 'Full name (as on your ID)' : 'Institution name', 'value' => $c['name'] ?? '', 'required' => true, 'icon' => $individual ? 'user' : 'building-2', 'autocomplete' => $individual ? 'name' : 'organization', 'class' => 'sm:col-span-2']) ?>

    <?php if (!empty($emailLocked)): ?>
        <div>
            <span class="label">Email</span>
            <p class="input flex items-center gap-2 bg-surface text-muted"><?= icon('lock', 'size-4') ?><?= e($c['email'] ?? '') ?></p>
            <p class="help">Your sign-in email. Ask the front desk to change it.</p>
        </div>
    <?php else: ?>
        <?= $this->component('input', ['name' => 'email', 'label' => $individual ? 'Email' : 'Official email', 'type' => 'email', 'value' => $c['email'] ?? '', 'required' => true, 'icon' => 'mail', 'autocomplete' => 'email']) ?>
    <?php endif ?>

    <div x-data="kycCheck('<?= $individual ? 'mobile' : '' ?>')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
        <?= $this->component('input', ['name' => 'mobile', 'label' => $individual ? 'Mobile number' : 'Official phone', 'type' => 'tel', 'value' => format_phone($c['mobile'] ?? null), 'required' => true, 'icon' => 'phone', 'placeholder' => $individual ? '+91 98765 43210' : '+91 474 245 0000', 'help' => $individual ? null : 'Landline with STD code, or a mobile number.', 'attrs' => ['inputmode' => 'tel']]) ?>
        <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
    </div>

    <?= $this->component('textarea', ['name' => 'address', 'label' => $individual ? 'Address' : 'Registered address', 'value' => $c['address'] ?? '', 'required' => true, 'rows' => 2, 'class' => 'sm:col-span-2', 'placeholder' => 'House / building, street, locality']) ?>
    <?= $this->component('input', ['name' => 'city', 'label' => 'City / town', 'value' => $c['city'] ?? '', 'required' => true, 'autocomplete' => 'address-level2']) ?>
    <div class="grid grid-cols-5 gap-3">
        <?= $this->component('input', ['name' => 'pincode', 'label' => 'PIN code', 'value' => $c['pincode'] ?? '', 'required' => true, 'class' => 'col-span-2', 'autocomplete' => 'postal-code', 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 6]]) ?>
        <?= $this->component('select', ['name' => 'state_code', 'label' => 'State', 'options' => IndianStates::options(), 'value' => $c['state_code'] ?? IndianStates::HOME, 'required' => true, 'class' => 'col-span-3']) ?>
    </div>

    <?= $this->component('textarea', [
        'name' => 'profile',
        'label' => $individual ? 'Short professional summary' : 'Institution profile',
        'value' => $c['profile'] ?? '',
        'required' => true,
        'rows' => $individual ? 3 : 5,
        'class' => 'sm:col-span-2',
        'placeholder' => $individual ? 'e.g. Freelance UI designer working with clients in Kochi and Bengaluru; needs a quiet desk three days a week.' : 'What the organisation does, team size, and how you plan to use the workspace.',
        'help' => $individual ? 'At least 30 characters.' : 'At least 80 characters.',
    ]) ?>
</div>
