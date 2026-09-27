<?php
/**
 * Role-aware sidebar from config('navigation.staff'). Items are shown when the user's role
 * has the item's ability; items whose route does not exist yet render as "Soon".
 * Collapsible on desktop ($store.sidebar.collapsed, remembered), slide-over on mobile.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed>|null $user
 * @var App\Enums\StaffRole|null $role
 */
$items = array_values(array_filter(
    (array) config('navigation.staff', []),
    static fn (array $i) => isset($i['section']) || ($role !== null && $role->can((string) ($i['can'] ?? ''))),
));
// drop section headers with no visible items after them
$visible = [];
foreach ($items as $idx => $item) {
    if (isset($item['section'])) {
        $next = $items[$idx + 1] ?? null;
        if ($next === null || isset($next['section'])) {
            continue;
        }
    }
    $visible[] = $item;
}
?>
<!-- Mobile backdrop -->
<div x-cloak x-show="$store.sidebar.mobileOpen" x-transition.opacity class="fixed inset-0 z-40 bg-brand-950/60 lg:hidden" @click="$store.sidebar.mobileOpen = false"></div>

<aside class="fixed inset-y-0 left-0 z-50 flex w-72 shrink-0 -translate-x-full flex-col bg-brand-950 text-white transition-all duration-300 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
       :class="[$store.sidebar.mobileOpen ? '!translate-x-0' : '', $store.sidebar.collapsed ? 'lg:w-20' : 'lg:w-72']"
       aria-label="Staff navigation">
    <div class="flex h-[72px] shrink-0 items-center justify-between gap-2 border-b border-white/10 px-5">
        <a href="<?= e(url('staff.dashboard')) ?>" class="overflow-hidden" :class="$store.sidebar.collapsed && 'lg:w-10'">
            <?= $this->partial('partials/logo', ['inverse' => true, 'sub' => 'Staff console']) ?>
        </a>
        <button type="button" class="rounded-lg p-1.5 text-white/60 hover:bg-white/10 hover:text-white lg:hidden" @click="$store.sidebar.mobileOpen = false" aria-label="Close menu"><?= icon('x', 'size-5') ?></button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-5">
        <?php foreach ($visible as $item): ?>
            <?php if (isset($item['section'])): ?>
                <p class="px-3 pt-4 pb-1 text-[11px] font-bold tracking-[0.14em] text-white/40 uppercase first:pt-0" :class="$store.sidebar.collapsed && 'lg:invisible lg:h-4 lg:p-0'"><?= e($item['section']) ?></p>
            <?php else:
                $exists = route_exists((string) $item['route']);
                $active = $exists && route_is((string) $item['route']);
            ?>
                <?php if ($exists): ?>
                    <a href="<?= e(url((string) $item['route'])) ?>" class="<?= e(class_names('side-link', ['side-link-active' => $active])) ?>" <?= $active ? 'aria-current="page"' : '' ?> title="<?= e($item['label']) ?>">
                        <?= icon((string) $item['icon'], 'size-5 shrink-0') ?>
                        <span class="truncate" :class="$store.sidebar.collapsed && 'lg:hidden'"><?= e($item['label']) ?></span>
                    </a>
                <?php else: ?>
                    <span class="side-link cursor-not-allowed opacity-50 hover:bg-transparent hover:text-white/70" title="<?= e($item['label']) ?> — coming in a later release" aria-disabled="true">
                        <?= icon((string) $item['icon'], 'size-5 shrink-0') ?>
                        <span class="flex-1 truncate" :class="$store.sidebar.collapsed && 'lg:hidden'"><?= e($item['label']) ?></span>
                        <span class="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-bold tracking-wide uppercase" :class="$store.sidebar.collapsed && 'lg:hidden'">Soon</span>
                    </span>
                <?php endif ?>
            <?php endif ?>
        <?php endforeach ?>
    </nav>

    <div class="border-t border-white/10 p-3">
        <button type="button" class="side-link hidden w-full lg:flex" @click="$store.sidebar.toggle()" :aria-label="$store.sidebar.collapsed ? 'Expand sidebar' : 'Collapse sidebar'">
            <span class="transition" :class="$store.sidebar.collapsed && 'rotate-180'"><?= icon('chevrons-left', 'size-5') ?></span>
            <span :class="$store.sidebar.collapsed && 'lg:hidden'">Collapse</span>
        </button>
    </div>
</aside>
