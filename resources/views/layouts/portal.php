<?php
/**
 * Visitor portal layout: public site chrome + a portal header band with sub-navigation.
 * Data: title, customer (safe row, optional), heading/subheading (optional).
 *
 * @var App\Core\Template $this
 * @var string|null $title
 */
$this->layout('layouts/site', ['hideFlash' => true, 'title' => $title ?? 'My account']);
$tabs = [
    ['portal.dashboard', 'Dashboard', 'layout-dashboard', ['portal.dashboard']],
    ['portal.profile', 'Profile', 'id-card', ['portal.profile', 'portal.wizard*']],
    ['portal.documents', 'Documents', 'file-text', ['portal.documents*']],
    ['portal.bookings', 'Bookings', 'calendar-check', ['portal.bookings*']],
    [null, 'Invoices', 'receipt-indian-rupee', []],
];
$who = (array) ($customer ?? []);
?>
<section class="border-b border-line bg-gradient-to-b from-brand-50/70 to-white">
    <div class="container-page pt-8 sm:pt-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <p class="eyebrow">My Commune</p>
                <h1 class="mt-2 truncate text-3xl font-extrabold sm:text-4xl"><?= e($heading ?? $title ?? 'My account') ?></h1>
                <?php if (!empty($subheading)): ?><p class="mt-1 text-muted"><?= e($subheading) ?></p><?php endif ?>
            </div>
            <?php if (!empty($who['unique_id'])): ?>
                <span class="inline-flex items-center gap-2 rounded-full border border-line bg-white px-3.5 py-1.5 font-mono text-sm font-semibold text-brand-900 shadow-xs"><?= icon('id-card', 'size-4 text-brand-600') ?><?= e($who['unique_id']) ?></span>
            <?php endif ?>
        </div>
        <nav class="-mb-px mt-6 flex gap-1 overflow-x-auto" aria-label="My account">
            <?php foreach ($tabs as [$route, $label, $ico, $match]):
                $active = $match !== [] && route_is(...$match);
            ?>
                <?php if ($route !== null): ?>
                    <a href="<?= e(url($route)) ?>" class="<?= e(class_names('inline-flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-3 text-sm font-semibold transition', $active ? 'border-accent-500 text-ink' : 'border-transparent text-muted hover:border-line hover:text-ink')) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= icon($ico, 'size-4') ?><?= e($label) ?>
                    </a>
                <?php else: ?>
                    <span class="inline-flex shrink-0 cursor-not-allowed items-center gap-2 border-b-2 border-transparent px-3.5 py-3 text-sm font-semibold text-muted/60" title="Coming soon">
                        <?= icon($ico, 'size-4') ?><?= e($label) ?><span class="rounded-full bg-surface-2 px-1.5 py-0.5 text-[10px] font-bold tracking-wide uppercase">Soon</span>
                    </span>
                <?php endif ?>
            <?php endforeach ?>
        </nav>
    </div>
</section>
<div class="container-page py-8 sm:py-10">
    <?= $this->partial('partials/flash', ['class' => 'mb-6']) ?>
    <?= $this->section('content') ?>
</div>
