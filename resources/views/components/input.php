<?php
/**
 * Text-like input with label, help text, old() value and validation error.
 *   <?= $this->component('input', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'icon' => 'mail']) ?>
 *
 * Value priority: old input (after failed validation) > $value.
 *
 * @var string $name
 * @var string|null $id    element id (default f-{name}; pass one when the field repeats on a page)
 * @var string|null $label
 * @var string|null $type  text|email|password|tel|date|number|...
 * @var scalar|null $value
 * @var string|null $placeholder
 * @var string|null $help
 * @var string|null $icon  leading Lucide icon
 * @var bool|null $required
 * @var string|null $autocomplete
 * @var string|null $class wrapper class
 * @var array<string, scalar|null>|null $attrs extra input attributes
 */
$id = !empty($id) ? (string) $id : 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$error = errors($name);
$type ??= 'text';
$current = $type === 'password' ? '' : old($name, $value ?? '');
?>
<div class="<?= e($class ?? '') ?>" <?= $type === 'password' ? 'x-data="{ show: false }"' : '' ?>>
    <?php if (!empty($label)): ?>
        <label for="<?= e($id) ?>" class="label"><?= e($label) ?><?php if (!empty($required)): ?> <span class="text-accent-500" aria-hidden="true">*</span><?php endif ?></label>
    <?php endif ?>
    <div class="relative">
        <?php if (!empty($icon)): ?>
            <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-muted"><?= icon($icon, 'size-[18px]') ?></span>
        <?php endif ?>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"
               <?= $type === 'password' ? ':type="show ? \'text\' : \'password\'"' : '' ?>
               value="<?= e(is_scalar($current) ? $current : '') ?>"
               class="<?= e(class_names('input', ['input-error' => $error !== null, 'pl-10' => !empty($icon), 'pr-11' => $type === 'password'])) ?>"
               <?= attrs([
                   'placeholder' => $placeholder ?? null,
                   'required' => !empty($required),
                   'autocomplete' => $autocomplete ?? null,
                   'aria-invalid' => $error !== null ? 'true' : null,
                   'aria-describedby' => $error !== null ? $id . '-error' : (!empty($help) ? $id . '-help' : null),
               ] + ($attrs ?? [])) ?>>
        <?php if ($type === 'password'): ?>
            <button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted hover:text-ink" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                <span x-show="!show"><?= icon('eye', 'size-[18px]') ?></span><span x-cloak x-show="show"><?= icon('eye-off', 'size-[18px]') ?></span>
            </button>
        <?php endif ?>
    </div>
    <?php if ($error !== null): ?>
        <p id="<?= e($id) ?>-error" class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e($error) ?></p>
    <?php elseif (!empty($help)): ?>
        <p id="<?= e($id) ?>-help" class="help"><?= e($help) ?></p>
    <?php endif ?>
</div>
