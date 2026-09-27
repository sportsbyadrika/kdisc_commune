<?php
/**
 * Section nav of the Layout & Pricing Designer: floor plans (per floor), rates, building, history.
 *
 * @var App\Core\Template $this
 * @var string $active  floor|rates|building|history
 * @var list<array<string, mixed>> $floors
 * @var string|null $floorSlug current floor (floor + history tabs)
 */
$floorSlug ??= (string) ($floors[0]['slug'] ?? '');
$user = staff();
$role = $user !== null ? App\Enums\StaffRole::tryFrom((string) $user['role']) : null;
$tabs = [
    'floor' => ['Floor plans', 'pen-tool', url('staff.layout.floor', ['floor' => $floorSlug]), 'layout.design'],
    'rates' => ['Rates', 'indian-rupee', url('staff.layout.rates'), 'pricing.manage'],
    'building' => ['Building & floors', 'building-2', url('staff.layout.building'), 'layout.design'],
    'history' => ['Version history', 'history', url('staff.layout.history', ['floor' => $floorSlug]), 'layout.design'],
];
?>
<nav class="flex items-center gap-1 overflow-x-auto rounded-full bg-surface-2 p-1" aria-label="Layout & pricing">
    <?php foreach ($tabs as $key => [$label, $ico, $href, $ability]): if ($role === null || !$role->can($ability)) { continue; } $on = $key === $active; ?>
        <a href="<?= e($href) ?>" class="<?= $on ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' ?> inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-bold whitespace-nowrap transition" <?= $on ? 'aria-current="page"' : '' ?>>
            <?= icon($ico, 'size-4') ?><?= e($label) ?>
        </a>
    <?php endforeach ?>
</nav>
