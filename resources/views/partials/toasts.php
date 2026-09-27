<?php
/**
 * Toast stack for an Alpine component that exposes `toasts` [{id, msg, tone, action}] and `runToast(t)`
 * (Space Explorer, Layout Designer). tone: info | success | warning | danger.
 *
 * @var string|null $position top (default) | bottom
 */
$bottom = ($position ?? 'top') === 'bottom';
?>
<div class="pointer-events-none fixed inset-x-0 z-[80] flex flex-col items-center gap-2 px-4 <?= $bottom ? 'bottom-6' : 'top-20 lg:top-24' ?>" aria-live="polite">
    <template x-for="t in toasts" :key="t.id">
        <div x-transition:enter="transition duration-300 ease-[var(--ease-spring)]" x-transition:enter-start="-translate-y-3 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
             class="toast" :class="{ info: 'bg-brand-950 text-white', success: 'bg-emerald-600 text-white', warning: 'bg-amber-50 text-amber-900 ring-1 ring-amber-200', danger: 'bg-red-600 text-white' }[t.tone]">
            <span class="mt-0.5 shrink-0" x-show="t.tone === 'success'"><?= icon('circle-check', 'size-4') ?></span>
            <span class="mt-0.5 shrink-0" x-show="t.tone === 'warning' || t.tone === 'danger'"><?= icon('triangle-alert', 'size-4') ?></span>
            <span class="mt-0.5 shrink-0" x-show="t.tone === 'info'"><?= icon('info', 'size-4') ?></span>
            <span class="flex-1" x-text="t.msg"></span>
            <button type="button" x-show="t.action" class="shrink-0 font-bold underline" @click="runToast(t)" x-text="t.action?.label"></button>
        </div>
    </template>
</div>
