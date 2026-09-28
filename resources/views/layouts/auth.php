<?php
/**
 * Split-screen layout for sign-in / register pages.
 * Data: panelTitle, panelText, panelImage (media path), panelTone ('brand'|'staff').
 *
 * @var App\Core\Template $this
 */
$this->layout('layouts/base', ['bodyClass' => 'min-h-full bg-surface']);
?>
<div class="grid grid-cols-1 min-h-screen lg:grid-cols-2">
    <aside class="relative hidden overflow-hidden bg-brand-950 lg:block">
        <img src="<?= e(media($panelImage ?? 'media/building.svg')) ?>" alt="" class="absolute inset-0 size-full object-cover opacity-60">
        <div class="absolute inset-0 bg-gradient-to-t from-brand-950 via-brand-950/85 to-brand-950/50"></div>
        <div class="relative flex h-full flex-col justify-between p-10 text-white">
            <a href="<?= e(url('home')) ?>" class="inline-flex"><?= $this->partial('partials/logo', ['inverse' => true]) ?></a>
            <div class="max-w-md">
                <p class="eyebrow !text-accent-400"><?= e($panelEyebrow ?? 'K-DISC · Commune') ?></p>
                <h2 class="mt-3 font-display text-4xl font-extrabold !text-white"><?= e($panelTitle ?? 'Work near home.') ?></h2>
                <p class="mt-4 text-lg text-white/75"><?= e($panelText ?? '') ?></p>
            </div>
            <p class="text-sm text-white/50">&copy; <?= date('Y') ?> Kerala Development and Innovation Strategic Council</p>
        </div>
    </aside>
    <main id="main" class="flex items-center justify-center px-4 py-12 sm:px-8">
        <div class="w-full max-w-md">
            <a href="<?= e(url('home')) ?>" class="mb-8 inline-flex lg:hidden"><?= $this->partial('partials/logo') ?></a>
            <?= $this->partial('partials/flash', ['class' => 'mb-6']) ?>
            <?= $this->section('content') ?>
        </div>
    </main>
</div>
