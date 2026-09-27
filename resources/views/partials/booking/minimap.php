<?php
/**
 * Read-only seat mini-map (resources/js/frontdesk.js `seatMiniMap`; the page must load space-render.js and
 * frontdesk.js in its head section and include partials/space/sprite once).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $map MiniMapPresenter::floor()
 * @var string|null $class
 * @var bool|null $legend
 */
$legend ??= true;
$mode = (string) ($map['mode'] ?? 'view');
?>
<div x-data="seatMiniMap" data-config="<?= e(json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" class="<?= e($class ?? '') ?>">
    <div class="overflow-hidden rounded-2xl bg-surface ring-1 ring-line">
        <svg x-ref="svg" xmlns="http://www.w3.org/2000/svg" class="block h-auto w-full" role="<?= $mode === 'pick' ? 'group' : 'img' ?>" aria-label="<?= e('Seat map · ' . $map['floor']['name']) ?>"></svg>
    </div>
    <?php if ($legend): ?>
        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
            <?php if ($mode === 'view'): ?>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-seat-selected"></span>This booking</span>
            <?php elseif ($mode === 'pick'): ?>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-seat-hold"></span>Current seat</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-seat-free"></span>Free — click to pick</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-seat-selected"></span>New seat</span>
            <?php endif ?>
            <?php if ($mode !== 'pick'): ?><span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-seat-free"></span>Free</span><?php endif ?>
            <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-red-200"></span>Occupied</span>
            <?php if ($mode === 'occupancy'): ?><span class="inline-flex items-center gap-1.5"><span class="size-3 rounded ring-2 ring-seat-free"></span>Checked in</span><?php endif ?>
        </div>
    <?php endif ?>
</div>
