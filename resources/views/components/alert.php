<?php
/**
 * Inline alert. Body is $message (escaped) or the captured $slot (HTML).
 *   <?= $this->component('alert', ['tone' => 'warning', 'title' => 'Heads up', 'message' => 'KYC pending']) ?>
 *
 * @var string|null $tone  success|danger|warning|info
 * @var string|null $title
 * @var string|null $message
 * @var string $slot
 * @var bool|null $dismissible
 * @var string|null $class
 */
$tone ??= 'info';
$styles = [
    'success' => ['bg-emerald-50 text-emerald-900 ring-emerald-200', 'circle-check', 'text-emerald-600'],
    'danger' => ['bg-red-50 text-red-900 ring-red-200', 'circle-alert', 'text-red-600'],
    'warning' => ['bg-amber-50 text-amber-900 ring-amber-200', 'triangle-alert', 'text-amber-600'],
    'info' => ['bg-sky-50 text-sky-900 ring-sky-200', 'info', 'text-sky-600'],
][$tone] ?? ['bg-surface text-ink ring-line', 'info', 'text-muted'];
?>
<div class="flex items-start gap-3 rounded-2xl p-4 text-sm ring-1 ring-inset <?= $styles[0] ?> animate-fade-up <?= e($class ?? '') ?>"
     <?= !empty($dismissible) ? 'x-data="{ show: true }" x-show="show" x-transition.opacity' : '' ?> role="<?= $tone === 'danger' ? 'alert' : 'status' ?>">
    <span class="<?= $styles[2] ?>"><?= icon($styles[1], 'size-5 shrink-0') ?></span>
    <div class="min-w-0 flex-1">
        <?php if (!empty($title)): ?><p class="font-semibold"><?= e($title) ?></p><?php endif ?>
        <?php if (!empty($message)): ?><p class="<?= !empty($title) ? 'mt-0.5 opacity-90' : 'font-medium' ?>"><?= e($message) ?></p><?php endif ?>
        <?= $slot ?? '' ?>
    </div>
    <?php if (!empty($dismissible)): ?>
        <button type="button" class="-m-1 rounded-lg p-1 opacity-60 hover:opacity-100" @click="show = false" aria-label="Dismiss"><?= icon('x', 'size-4') ?></button>
    <?php endif ?>
</div>
