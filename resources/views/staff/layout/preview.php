<?php
/**
 * Draft preview: the layout version rendered with the visitor Space Explorer (same partial + explorer.js, in
 * preview mode — no holds, no polling). Availability colours are real: bookings match draft seats by seat_key.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $floor
 * @var array<string, mixed> $version layout_versions row
 * @var array<string, mixed> $config ExplorerPresenter::floorConfig(..., versionId)
 */
$this->layout('layouts/staff', [
    'title' => 'Preview',
    'wide' => true,
    'hideTitle' => true,
    'breadcrumb' => [['Layout & pricing', url('staff.layout.index')], [(string) $floor['name'], url('staff.layout.floor', ['floor' => (string) $floor['slug']])], ['Preview']],
]);
$status = App\Enums\LayoutStatus::from((string) $version['status']);
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/vendor/panzoom.min.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/explorer.js')) ?>"></script>
<?php $this->stop() ?>

<div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl bg-brand-950 px-5 py-3.5 text-white shadow-lg">
    <span class="grid size-9 place-items-center rounded-xl bg-white/10"><?= icon('eye', 'size-5') ?></span>
    <div class="min-w-0 flex-1">
        <p class="font-bold">Visitor preview · <?= e((string) $floor['name']) ?> · version <?= (int) $version['version_no'] ?> <span class="badge badge-<?= e($status->tone()) ?> ml-1"><?= e($status->label()) ?></span></p>
        <p class="text-sm text-white/70">Exactly what visitors will see once published — prices and availability are live. Seats can’t be held in preview.</p>
    </div>
    <a href="<?= e(url('staff.layout.floor', ['floor' => (string) $floor['slug']])) ?>" class="btn btn-sm bg-white text-ink hover:bg-white/90"><?= icon('arrow-left', 'size-4') ?>Back to designer</a>
</div>
<?= $this->partial('partials/space/explorer', ['config' => $config, 'staffMode' => false]) ?>
