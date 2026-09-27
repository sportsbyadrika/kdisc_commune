<?php
/**
 * Button / link-button.
 *   <?= $this->component('button', ['label' => 'Book a Seat', 'href' => url('spaces'), 'variant' => 'primary', 'iconRight' => 'arrow-right']) ?>
 *   <?= $this->component('button', ['label' => 'Save', 'type' => 'submit', 'variant' => 'brand', 'icon' => 'check']) ?>
 *
 * @var string $label
 * @var string|null $href       renders <a> when set
 * @var string|null $variant    primary|brand|dark|outline|ghost|light|danger (default primary)
 * @var string|null $size       sm|md|lg
 * @var string|null $icon       Lucide icon before label
 * @var string|null $iconRight  Lucide icon after label
 * @var string|null $type       button type (default "button")
 * @var string|null $class
 * @var array<string, scalar|null> $attrs extra attributes
 */
$classes = class_names('btn', 'btn-' . ($variant ?? 'primary'), ['btn-sm' => ($size ?? '') === 'sm', 'btn-lg' => ($size ?? '') === 'lg'], $class ?? '');
$inner = (isset($icon) ? icon($icon, 'size-4') : '') . '<span>' . e($label ?? '') . '</span>' . (isset($iconRight) ? icon($iconRight, 'size-4') : '');
?>
<?php if (!empty($href)): ?>
<a href="<?= e($href) ?>" class="<?= e($classes) ?>"<?= attrs($attrs ?? []) ?>><?= $inner ?></a>
<?php else: ?>
<button type="<?= e($type ?? 'button') ?>" class="<?= e($classes) ?>"<?= attrs($attrs ?? []) ?>><?= $inner ?></button>
<?php endif;
