<?php
/**
 * KPI tile.
 *   <?= $this->component('stat', ['label' => 'Seats', 'value' => 160, 'icon' => 'armchair', 'tone' => 'brand', 'hint' => 'across 2 floors']) ?>
 *
 * @var string $label
 * @var scalar $value
 * @var string|null $icon
 * @var string|null $tone brand|success|warning|danger|info|accent
 * @var string|null $hint
 * @var string|null $href
 */
$tones = [
    'brand' => 'bg-brand-50 text-brand-700', 'success' => 'bg-emerald-50 text-emerald-700', 'warning' => 'bg-amber-50 text-amber-700',
    'danger' => 'bg-red-50 text-red-700', 'info' => 'bg-sky-50 text-sky-700', 'accent' => 'bg-accent-50 text-accent-600',
];
$tag = !empty($href) ? 'a' : 'div';
?>
<<?= $tag ?> <?= !empty($href) ? 'href="' . e($href) . '"' : '' ?> class="card card-body flex items-start justify-between gap-4 <?= !empty($href) ? 'card-hover' : '' ?>">
    <div class="min-w-0">
        <p class="text-sm font-medium text-muted"><?= e($label) ?></p>
        <p class="mt-2 font-display text-3xl font-extrabold tracking-tight tabular-nums"><?= e($value) ?></p>
        <?php if (!empty($hint)): ?><p class="mt-1 text-xs text-muted"><?= e($hint) ?></p><?php endif ?>
    </div>
    <?php if (!empty($icon)): ?>
        <span class="grid size-11 shrink-0 place-items-center rounded-2xl <?= $tones[$tone ?? 'brand'] ?? $tones['brand'] ?>"><?= icon($icon, 'size-5') ?></span>
    <?php endif ?>
</<?= $tag ?>>
