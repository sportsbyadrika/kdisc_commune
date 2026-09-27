<?php
/**
 * Space Explorer — Level 2 (floor map). All behaviour lives in partials/space/explorer + resources/js/explorer.js.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $floor
 * @var array<string, mixed> $config
 * @var array{from: string, to: string, type: string} $filters
 */
$this->layout('layouts/site', ['description' => 'Pick your seat on the ' . $floor['name'] . ' of Commune Kottarakara — live availability, prices and facilities.']);
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/vendor/panzoom.min.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/explorer.js')) ?>"></script>
<?php $this->stop() ?>

<div class="bg-gradient-to-b from-surface via-white to-white">
    <div class="mx-auto w-full max-w-[1560px] px-4 pt-5 pb-10 sm:px-6 lg:px-8 lg:pt-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <?= $this->component('breadcrumb', ['items' => [['Home', url('home')], ['Space Explorer', url('spaces.explore', ['from' => $filters['from'], 'to' => $filters['to']])], [(string) $floor['name']]]]) ?>
            <a href="<?= e(url('pricing')) ?>" class="hidden items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline sm:inline-flex"><?= icon('badge-indian-rupee', 'size-4') ?>Pricing &amp; GST</a>
        </div>
        <?= $this->partial('partials/space/explorer', ['config' => $config, 'staffMode' => false]) ?>
    </div>
</div>
