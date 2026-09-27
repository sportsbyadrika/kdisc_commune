<?php
/**
 * Inner-page hero band. Optional background image and action slot.
 *   <?php $this->begin('page-hero', ['eyebrow' => 'Pricing', 'title' => 'Simple, transparent pricing', 'subtitle' => '...',
 *         'breadcrumb' => [['Home', url('home')], ['Pricing']]]) ?>
 *       <a class="btn btn-primary" href="...">Book</a>
 *   <?= $this->end() ?>
 *
 * @var string $title
 * @var string|null $eyebrow
 * @var string|null $subtitle
 * @var string|null $image media path
 * @var list<array{0: string, 1?: string|null}>|null $breadcrumb
 * @var string $slot
 */
?>
<section class="relative isolate overflow-hidden bg-brand-950 text-white">
    <?php if (!empty($image)): ?>
        <img src="<?= e(media($image)) ?>" alt="" class="absolute inset-0 -z-10 size-full object-cover opacity-40">
    <?php endif ?>
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(60rem_30rem_at_110%_-20%,var(--color-accent-500)_0%,transparent_55%),radial-gradient(50rem_30rem_at_-10%_120%,var(--color-brand-600)_0%,transparent_60%)] opacity-50"></div>
    <div class="container-page py-14 sm:py-20">
        <?php if (!empty($breadcrumb)): ?><?= $this->component('breadcrumb', ['items' => $breadcrumb, 'inverse' => true]) ?><?php endif ?>
        <?php if (!empty($eyebrow)): ?><p class="eyebrow mt-6 !text-accent-400"><?= e($eyebrow) ?></p><?php endif ?>
        <h1 class="mt-3 max-w-3xl font-display text-4xl font-extrabold !text-white sm:text-5xl"><?= e($title) ?></h1>
        <?php if (!empty($subtitle)): ?><p class="mt-4 max-w-2xl text-lg text-white/75"><?= e($subtitle) ?></p><?php endif ?>
        <?php if (trim($slot ?? '') !== ''): ?><div class="mt-8 flex flex-wrap gap-3"><?= $slot ?></div><?php endif ?>
    </div>
</section>
