<?php
/**
 * Placeholder wordmark — replace with the K-DISC / Commune logo files when supplied.
 * @var bool|null $inverse  white text for dark backgrounds
 * @var string|null $sub
 */
$inverse = !empty($inverse);
?>
<span class="inline-flex items-center gap-2.5">
    <span class="grid size-10 place-items-center rounded-xl <?= $inverse ? 'bg-white text-brand-900' : 'bg-brand-900 text-white' ?> shadow-sm">
        <svg viewBox="0 0 32 32" class="size-6" aria-hidden="true"><path d="M22 9.5A9 9 0 1 0 22 22.5" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"/><circle cx="17" cy="16" r="3.2" class="fill-accent-500"/></svg>
    </span>
    <span class="text-left leading-none">
        <span class="block font-display text-xl font-extrabold tracking-tight <?= $inverse ? 'text-white' : 'text-brand-900' ?>">Commune</span>
        <span class="mt-0.5 block text-[11px] font-semibold tracking-[0.14em] uppercase <?= $inverse ? 'text-white/60' : 'text-muted' ?>"><?= e($sub ?? 'by K-DISC · Kottarakara') ?></span>
    </span>
</span>
