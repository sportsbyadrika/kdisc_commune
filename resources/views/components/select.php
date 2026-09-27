<?php
/**
 * Select with label + error. Options: value => label (e.g. StaffRole::options()).
 *   <?= $this->component('select', ['name' => 'type', 'label' => 'Visitor type', 'options' => CustomerType::options(), 'placeholder' => 'Choose…']) ?>
 *
 * @var string $name
 * @var array<string, string> $options
 * @var string|null $label
 * @var scalar|null $value
 * @var string|null $placeholder
 * @var string|null $help
 * @var bool|null $required
 * @var string|null $class
 * @var array<string, scalar|null>|null $attrs
 */
$id = 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$error = errors($name);
$current = (string) old($name, $value ?? '');
?>
<div class="<?= e($class ?? '') ?>">
    <?php if (!empty($label)): ?>
        <label for="<?= e($id) ?>" class="label"><?= e($label) ?><?php if (!empty($required)): ?> <span class="text-accent-500" aria-hidden="true">*</span><?php endif ?></label>
    <?php endif ?>
    <select id="<?= e($id) ?>" name="<?= e($name) ?>" class="<?= e(class_names('input pr-10', ['input-error' => $error !== null])) ?>"
        <?= attrs(['required' => !empty($required), 'aria-invalid' => $error !== null ? 'true' : null] + ($attrs ?? [])) ?>>
        <?php if (isset($placeholder)): ?><option value=""><?= e($placeholder) ?></option><?php endif ?>
        <?php foreach ($options as $val => $text): ?>
            <option value="<?= e($val) ?>" <?= (string) $val === $current ? 'selected' : '' ?>><?= e($text) ?></option>
        <?php endforeach ?>
    </select>
    <?php if ($error !== null): ?>
        <p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e($error) ?></p>
    <?php elseif (!empty($help)): ?>
        <p class="help"><?= e($help) ?></p>
    <?php endif ?>
</div>
