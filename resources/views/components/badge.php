<?php
/**
 * Status / category pill.
 *   <?= $this->component('badge', ['label' => $status->label(), 'tone' => $status->tone()]) ?>
 *
 * @var string $label
 * @var string|null $tone  neutral|brand|success|warning|danger|info|accent
 * @var string|null $icon
 * @var bool|null $dot     leading status dot
 * @var string|null $class
 */
$tone ??= 'neutral';
?>
<span class="<?= e(class_names('badge', 'badge-' . $tone, $class ?? '')) ?>">
    <?php if (!empty($dot)): ?><span class="size-1.5 rounded-full bg-current"></span><?php endif ?>
    <?= isset($icon) ? icon($icon, 'size-3.5') : '' ?><?= e($label) ?>
</span>
