<?php
/**
 * Accessible modal dialog (Alpine). Open from anywhere with:
 *   <button @click="$dispatch('open-modal', 'confirm-cancel')">Cancel booking</button>
 *
 *   <?php $this->begin('modal', ['id' => 'confirm-cancel', 'title' => 'Cancel booking?']) ?>
 *       <p>This cannot be undone.</p>
 *   <?= $this->end() ?>
 *
 * @var string $id
 * @var string|null $title
 * @var string $slot   body HTML
 * @var string|null $footer HTML for the action row
 * @var string|null $size sm|md|lg
 */
$width = ['sm' => 'max-w-sm', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl'][$size ?? 'md'] ?? 'max-w-lg';
?>
<div x-data="{ open: false }" x-cloak
     @open-modal.window="if ($event.detail === '<?= e($id) ?>') { open = true; $nextTick(() => $refs.panel.focus()) }"
     @close-modal.window="if ($event.detail === '<?= e($id) ?>') open = false"
     @keydown.escape.window="open = false">
    <div x-show="open" class="fixed inset-0 z-[60] flex items-end justify-center p-0 sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="<?= e($id) ?>-title">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" @click="open = false"></div>
        <div x-show="open" x-ref="panel" tabindex="-1"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-6 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="relative w-full <?= $width ?> rounded-t-3xl bg-white shadow-2xl outline-none sm:rounded-3xl">
            <div class="flex items-center justify-between border-b border-line px-6 py-4">
                <h2 id="<?= e($id) ?>-title" class="text-lg font-bold"><?= e($title ?? '') ?></h2>
                <button type="button" class="btn btn-ghost btn-icon -mr-2" @click="open = false" aria-label="Close"><?= icon('x', 'size-5') ?></button>
            </div>
            <div class="px-6 py-5 text-sm text-ink/80"><?= $slot ?></div>
            <?php if (!empty($footer)): ?>
                <div class="flex justify-end gap-2 rounded-b-3xl border-t border-line bg-surface px-6 py-4"><?= $footer ?></div>
            <?php endif ?>
        </div>
    </div>
</div>
