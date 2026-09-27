<?php
/**
 * Checkbox / consent. Sends $value (default "1") when checked.
 *
 * @var string $name
 * @var string $label
 * @var bool|null $checked
 * @var string|null $value
 * @var string|null $help
 * @var string|null $class
 */
$id = 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$error = errors($name);
$isChecked = (bool) old($name, !empty($checked) ? ($value ?? '1') : '');
?>
<div class="<?= e($class ?? '') ?>">
    <label for="<?= e($id) ?>" class="flex cursor-pointer items-start gap-3 text-sm">
        <input id="<?= e($id) ?>" type="checkbox" name="<?= e($name) ?>" value="<?= e($value ?? '1') ?>" <?= $isChecked ? 'checked' : '' ?>
               class="mt-0.5 size-4.5 rounded border-line text-brand-600 accent-brand-600 focus:ring-brand-600">
        <span><span class="font-medium text-ink"><?= e($label) ?></span><?php if (!empty($help)): ?><span class="mt-0.5 block text-xs text-muted"><?= e($help) ?></span><?php endif ?></span>
    </label>
    <?php if ($error !== null): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e($error) ?></p><?php endif ?>
</div>
