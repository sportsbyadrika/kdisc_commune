<?php
/**
 * Empty state.
 *   <?= $this->component('empty', ['icon' => 'calendar', 'title' => 'No bookings yet', 'text' => '...', 'action' => ['label' => 'Book a seat', 'href' => url('spaces')]]) ?>
 *
 * @var string|null $icon
 * @var string $title
 * @var string|null $text
 * @var array<string, mixed>|null $action button component props
 * @var string|null $class
 */
?>
<div class="<?= e(class_names('flex flex-col items-center rounded-3xl border-2 border-dashed border-line px-6 py-12 text-center', $class ?? '')) ?>">
    <span class="grid size-14 place-items-center rounded-2xl bg-surface text-muted"><?= icon($icon ?? 'inbox', 'size-7') ?></span>
    <h3 class="mt-4 text-lg font-bold"><?= e($title) ?></h3>
    <?php if (!empty($text)): ?><p class="mt-1 max-w-sm text-sm text-muted"><?= e($text) ?></p><?php endif ?>
    <?php if (!empty($action)): ?><div class="mt-5"><?= $this->component('button', $action + ['size' => 'sm']) ?></div><?php endif ?>
</div>
