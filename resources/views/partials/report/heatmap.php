<?php
/**
 * Read-only occupancy heat-map of one floor (resources/js/dashboards.js `heatMap`). The page must load space-render.js
 * then dashboards.js in its head section and include partials/space/sprite once.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $map DashboardService::heatmaps() item
 * @var string|null $tableUrl link to the same numbers as a table (occupancy report by seat)
 */
?>
<figure class="min-w-0">
    <figcaption class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <span class="font-display text-base font-bold"><?= e($map['floor']['name']) ?></span>
        <span class="text-sm text-muted"><strong class="tabular-nums text-ink"><?= e(number_format((float) $map['summary']['pct'], 1)) ?>%</strong> of seat-days occupied · <?= (int) $map['summary']['units'] ?> units</span>
    </figcaption>
    <div x-data="heatMap" x-ref="wrap" data-config="<?= e(json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" class="hm relative">
        <div class="overflow-hidden rounded-2xl bg-white ring-1 ring-line">
            <svg x-ref="svg" xmlns="http://www.w3.org/2000/svg" class="block h-auto w-full" role="group" aria-label="<?= e('Occupancy heat-map · ' . $map['floor']['name']) ?>"></svg>
        </div>
        <div x-cloak x-show="tip.show" class="hm-tip" :style="`left:${tip.x}px;top:${tip.y}px`">
            <p class="font-bold" x-text="tip.title"></p>
            <p x-text="tip.line"></p>
            <p class="text-white/70" x-text="tip.sub"></p>
        </div>
    </div>
</figure>
