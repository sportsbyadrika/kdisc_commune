<?php
/**
 * @var string $name
 * @var string|null $id    element id (default f-{name}; pass one when the field repeats on a page)
 * @var string|null $label
 * @var scalar|null $value
 * @var string|null $placeholder
 * @var string|null $help
 * @var int|null $rows
 * @var bool|null $required
 * @var string|null $class
 * @var array<string, scalar|null>|null $attrs
 */
$id = !empty($id) ? (string) $id : 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$error = errors($name);
?>
<div class="<?= e($class ?? '') ?>">
    <?php if (!empty($label)): ?>
        <label for="<?= e($id) ?>" class="label"><?= e($label) ?><?php if (!empty($required)): ?> <span class="text-accent-500" aria-hidden="true">*</span><?php endif ?></label>
    <?php endif ?>
    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= (int) ($rows ?? 4) ?>" class="<?= e(class_names('input', ['input-error' => $error !== null])) ?>"
        <?= attrs(['placeholder' => $placeholder ?? null, 'required' => !empty($required), 'aria-invalid' => $error !== null ? 'true' : null] + ($attrs ?? [])) ?>><?= e(old($name, $value ?? '')) ?></textarea>
    <?php if ($error !== null): ?>
        <p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e($error) ?></p>
    <?php elseif (!empty($help)): ?>
        <p class="help"><?= e($help) ?></p>
    <?php endif ?>
</div>
