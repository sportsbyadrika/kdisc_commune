<?php
/**
 * Legend for the occupancy heat-maps (DashboardService::HEAT_BINS → --color-heat-1..5).
 *
 * @var App\Core\Template $this
 * @var string|null $tableUrl
 */
use App\Services\Reports\DashboardService;

$swatches = ['bg-heat-1', 'bg-heat-2', 'bg-heat-3', 'bg-heat-4', 'bg-heat-5'];
$i = 0;
?>
<div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted">
    <span class="font-semibold text-ink">Seat-days occupied</span>
    <span class="inline-flex items-center gap-1.5"><span class="hm-swatch border border-dashed border-heat-2 bg-white ring-0"></span>0% (unused)</span>
    <?php foreach (DashboardService::HEAT_BINS as $label): ?>
        <span class="inline-flex items-center gap-1.5"><span class="hm-swatch <?= $swatches[$i++] ?>"></span><?= e($label) ?></span>
    <?php endforeach ?>
    <span class="inline-flex items-center gap-1.5"><span class="hm-swatch bg-seat-blocked"></span>Blocked</span>
    <?php if (!empty($tableUrl)): ?><a href="<?= e($tableUrl) ?>" class="ml-auto inline-flex items-center gap-1 font-semibold text-brand-700 hover:underline"><?= icon('table-2', 'size-3.5') ?>Seat-by-seat table</a><?php endif ?>
</div>
