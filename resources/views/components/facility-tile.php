<?php
/**
 * Facility tile for grids (emoji + Lucide icon, name, price or "Included").
 *
 * @var array<string, mixed> $facility row from `facilities`
 * @var bool|null $compact
 */
$kind = App\Enums\FacilityKind::tryFrom((string) $facility['kind']);
$unit = App\Enums\FacilityUnit::tryFrom((string) ($facility['unit'] ?? ''));
?>
<div class="group card flex items-center gap-4 p-4 transition hover:border-brand-200">
    <span class="relative grid size-14 shrink-0 place-items-center rounded-2xl bg-surface text-2xl transition group-hover:bg-brand-50" aria-hidden="true">
        <?= e($facility['emoji'] ?? '') ?>
        <span class="absolute -right-1 -bottom-1 grid size-6 place-items-center rounded-full bg-white text-brand-700 shadow ring-1 ring-line"><?= icon((string) ($facility['icon'] ?? 'sparkles'), 'size-3.5') ?></span>
    </span>
    <div class="min-w-0">
        <p class="font-semibold text-ink"><?= e($facility['name']) ?></p>
        <?php if (empty($compact) && !empty($facility['description'])): ?><p class="mt-0.5 text-sm text-muted"><?= e($facility['description']) ?></p><?php endif ?>
        <p class="mt-1 text-xs font-semibold <?= $kind === App\Enums\FacilityKind::Addon ? 'text-accent-600' : 'text-emerald-700' ?>">
            <?php if ($kind === App\Enums\FacilityKind::Addon): ?>
                <?= e(money($facility['price'])) ?> <?= e($unit?->label() ?? '') ?> + GST
                <?php if ($facility['stock_qty'] !== null): ?><span class="font-medium text-muted"> · <?= (int) $facility['stock_qty'] ?> available</span><?php endif ?>
            <?php elseif ($kind === App\Enums\FacilityKind::Included): ?>
                Included with every seat
            <?php else: ?>
                <span class="text-muted">Shown on the floor map</span>
            <?php endif ?>
        </p>
    </div>
</div>
