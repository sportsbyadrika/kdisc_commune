<?php
/**
 * Aadhaar input with live Verhoeff check. The full number is NEVER rendered back: when one is on
 * file only "XXXX XXXX 1234" is shown and a blank field keeps it.
 *
 * @var App\Core\Template $this
 * @var string $name        aadhaar | sig_aadhaar
 * @var string $label
 * @var string|null $last4  aadhaar_last4 on file
 */
use App\Services\Kyc\AadhaarVault;

$onFile = isset($last4) && $last4 !== '';
?>
<div x-data="kycCheck('aadhaar')" @input="check($event.target.value)" @focusout="check($event.target.value, true)">
    <?= $this->component('input', [
        'name' => $name,
        'label' => $label,
        'value' => '',
        'required' => !$onFile,
        'icon' => 'fingerprint',
        'placeholder' => $onFile ? AadhaarVault::mask($last4) . ' on file' : '1234 5678 9012',
        'help' => $onFile ? 'On file: ' . AadhaarVault::mask($last4) . '. Leave blank to keep it, or enter a new number to replace it.' : '12 digits. Stored encrypted; only the last 4 digits are ever shown.',
        'autocomplete' => 'off',
        'attrs' => ['inputmode' => 'numeric', 'maxlength' => 14, 'spellcheck' => 'false'],
    ]) ?>
    <p x-cloak x-show="msg" x-text="msg" class="error-text"></p>
    <p x-cloak x-show="ok" class="help !text-emerald-700 flex items-center gap-1"><?= icon('circle-check', 'size-3.5') ?> Valid Aadhaar number</p>
</div>
