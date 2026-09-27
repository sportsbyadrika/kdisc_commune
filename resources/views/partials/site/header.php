<?php
/**
 * Public header: slim utility bar + sticky main bar with dropdown nav, "Book a Seat" CTA
 * and an Alpine slide-out drawer on mobile. Menu items come from config/navigation.php.
 *
 * @var App\Core\Template $this
 */
$org = (array) config('app.org');
$menu = (array) config('navigation.site', []);
$href = static function (array $item): ?string {
    if (isset($item['href'])) {
        return url($item['href']);
    }
    return isset($item['route']) && route_exists($item['route']) ? url($item['route']) : null;
};
$isActive = static fn (array $item): bool => isset($item['route']) && route_is($item['route']);
$visitor = visitor();
$visitorName = (string) ($visitor['name'] ?? '');
$initials = '';
foreach (array_slice(explode(' ', $visitorName), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
?>
<div class="bg-brand-950 text-[13px] text-white/75">
    <div class="container-page flex h-9 items-center justify-between gap-4">
        <div class="flex min-w-0 items-center gap-5">
            <span class="inline-flex items-center gap-1.5 truncate"><?= icon('map-pin', 'size-3.5 text-accent-400') ?> Commune Kottarakara · K-DISC</span>
            <span class="hidden items-center gap-1.5 md:inline-flex"><?= icon('clock', 'size-3.5') ?> <?= e($org['hours'] ?? '') ?></span>
        </div>
        <div class="flex items-center gap-5">
            <a href="tel:<?= e(preg_replace('/\s+/', '', (string) ($org['phone'] ?? ''))) ?>" class="hidden items-center gap-1.5 hover:text-white sm:inline-flex"><?= icon('phone', 'size-3.5') ?> <?= e($org['phone'] ?? '') ?></a>
            <a href="mailto:<?= e($org['email'] ?? '') ?>" class="hidden items-center gap-1.5 hover:text-white lg:inline-flex"><?= icon('mail', 'size-3.5') ?> <?= e($org['email'] ?? '') ?></a>
            <a href="<?= e(url('staff.login')) ?>" class="inline-flex items-center gap-1.5 font-medium hover:text-white"><?= icon('shield-check', 'size-3.5') ?> Staff</a>
        </div>
    </div>
</div>

<header x-data="{ drawer: false, scrolled: false }"
        x-init="scrolled = window.scrollY > 8"
        @scroll.window="scrolled = window.scrollY > 8"
        @keydown.escape.window="drawer = false"
        :class="scrolled ? 'shadow-[0_6px_24px_-12px_rgb(16_24_40/.25)] bg-white/95' : 'bg-white'"
        class="sticky top-0 z-40 border-b border-line/70 backdrop-blur transition-shadow">
    <div class="container-page flex h-[72px] items-center justify-between gap-6">
        <a href="<?= e(url('home')) ?>" class="shrink-0" aria-label="Commune home"><?= $this->partial('partials/logo') ?></a>

        <nav class="hidden items-center gap-1 lg:flex" aria-label="Main">
            <?php foreach ($menu as $item): ?>
                <?php if (!empty($item['children'])): ?>
                    <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" @focusout="if (!$el.contains($event.relatedTarget)) open = false">
                        <button type="button" class="nav-link <?= $isActive($item) ? 'nav-link-active bg-surface-2' : '' ?>" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true">
                            <?= e($item['label']) ?> <?= icon('chevron-down', 'size-4 transition', '') ?>
                        </button>
                        <div x-cloak x-show="open" x-transition.opacity.scale.origin.top.left.duration.150ms
                             class="absolute left-0 top-full z-50 w-80 pt-3">
                            <div class="rounded-2xl border border-line/80 bg-white p-2 shadow-[var(--shadow-card-hover)]">
                                <?php foreach ($item['children'] as $child): $link = $href($child); ?>
                                    <a href="<?= e($link ?? '#') ?>" class="group flex items-start gap-3 rounded-xl p-3 transition hover:bg-surface">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-700 transition group-hover:bg-brand-600 group-hover:text-white"><?= icon($child['icon'] ?? 'arrow-right', 'size-[18px]') ?></span>
                                        <span>
                                            <span class="block text-sm font-semibold text-ink"><?= e($child['label']) ?></span>
                                            <?php if (!empty($child['text'])): ?><span class="block text-xs text-muted"><?= e($child['text']) ?></span><?php endif ?>
                                        </span>
                                    </a>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= e($href($item) ?? '#') ?>" class="nav-link <?= $isActive($item) ? 'nav-link-active bg-surface-2' : '' ?>" <?= $isActive($item) ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
                <?php endif ?>
            <?php endforeach ?>
        </nav>

        <div class="flex items-center gap-2">
            <?php if ($visitor !== null): ?>
                <div class="relative hidden md:block" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-full py-1 pr-3 pl-1 transition hover:bg-surface-2" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true">
                        <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white"><?= e($initials) ?></span>
                        <span class="text-sm font-semibold">My account</span><?= icon('chevron-down', 'size-4 text-muted') ?>
                    </button>
                    <div x-cloak x-show="open" x-transition.opacity.scale.origin.top.right class="absolute right-0 z-50 mt-2 w-64 rounded-2xl border border-line bg-white p-2 shadow-[var(--shadow-card-hover)]">
                        <div class="px-3 py-2">
                            <p class="truncate text-sm font-semibold"><?= e($visitorName) ?></p>
                            <p class="truncate text-xs text-muted"><?= e($visitor['email']) ?></p>
                        </div>
                        <div class="my-1 border-t border-line"></div>
                        <a href="<?= e(url('portal.dashboard')) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium hover:bg-surface"><?= icon('layout-dashboard', 'size-4 text-muted') ?> Dashboard</a>
                        <a href="<?= e(url('portal.profile')) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium hover:bg-surface"><?= icon('id-card', 'size-4 text-muted') ?> My profile</a>
                        <a href="<?= e(url('portal.documents')) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium hover:bg-surface"><?= icon('file-text', 'size-4 text-muted') ?> Documents</a>
                        <div class="my-1 border-t border-line"></div>
                        <form method="post" action="<?= e(url('portal.logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"><?= icon('log-out', 'size-4') ?> Sign out</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= e(url('portal.login')) ?>" class="btn btn-ghost hidden md:inline-flex"><?= icon('circle-user-round', 'size-[18px]') ?> Sign in</a>
                <a href="<?= e(url('portal.register')) ?>" class="btn btn-outline hidden xl:inline-flex">Register</a>
            <?php endif ?>
            <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-primary btn-lg hidden !px-6 !py-3 sm:inline-flex">Book a Seat <?= icon('arrow-right', 'size-4') ?></a>
            <button type="button" class="btn btn-ghost btn-icon lg:hidden" @click="drawer = true" aria-label="Open menu" :aria-expanded="drawer.toString()" aria-controls="mobile-drawer">
                <?= icon('menu', 'size-6') ?>
            </button>
        </div>
    </div>

    <!-- Mobile drawer (teleported to <body>: the sticky header's backdrop-filter would otherwise trap position:fixed) -->
    <template x-teleport="body">
    <div x-cloak x-show="drawer" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
        <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" @click="drawer = false"></div>
        <aside id="mobile-drawer" x-show="drawer"
               x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
               class="absolute inset-y-0 right-0 flex w-[88%] max-w-sm flex-col bg-white shadow-2xl">
            <div class="flex h-[72px] items-center justify-between border-b border-line px-5">
                <?= $this->partial('partials/logo') ?>
                <button type="button" class="btn btn-ghost btn-icon" @click="drawer = false" aria-label="Close menu"><?= icon('x', 'size-6') ?></button>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Mobile">
                <?php foreach ($menu as $item): ?>
                    <?php if (!empty($item['children'])): ?>
                        <div x-data="{ open: false }" class="border-b border-line/60">
                            <button type="button" class="flex w-full items-center justify-between px-3 py-3.5 text-base font-semibold" @click="open = !open" :aria-expanded="open.toString()">
                                <?= e($item['label']) ?>
                                <span :class="open && 'rotate-180'" class="transition"><?= icon('chevron-down', 'size-5 text-muted') ?></span>
                            </button>
                            <div x-show="open" x-transition.opacity class="pb-3">
                                <?php foreach ($item['children'] as $child): ?>
                                    <a href="<?= e($href($child) ?? '#') ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-ink/80 hover:bg-surface" @click="drawer = false">
                                        <span class="text-brand-600"><?= icon($child['icon'] ?? 'arrow-right', 'size-[18px]') ?></span><?= e($child['label']) ?>
                                    </a>
                                <?php endforeach ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= e($href($item) ?? '#') ?>" class="block border-b border-line/60 px-3 py-3.5 text-base font-semibold <?= $isActive($item) ? 'text-brand-600' : '' ?>"><?= e($item['label']) ?></a>
                    <?php endif ?>
                <?php endforeach ?>
            </nav>
            <div class="space-y-3 border-t border-line p-5">
                <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-primary btn-lg w-full">Book a Seat <?= icon('arrow-right', 'size-4') ?></a>
                <?php if ($visitor !== null): ?>
                    <a href="<?= e(url('portal.dashboard')) ?>" class="btn btn-outline w-full"><?= icon('layout-dashboard', 'size-[18px]') ?> My account</a>
                    <form method="post" action="<?= e(url('portal.logout')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-ghost w-full !text-red-600"><?= icon('log-out', 'size-4') ?> Sign out</button></form>
                <?php else: ?>
                    <div class="grid grid-cols-2 gap-3">
                        <a href="<?= e(url('portal.login')) ?>" class="btn btn-outline"><?= icon('circle-user-round', 'size-[18px]') ?> Sign in</a>
                        <a href="<?= e(url('portal.register')) ?>" class="btn btn-outline"><?= icon('user-plus', 'size-[18px]') ?> Register</a>
                    </div>
                <?php endif ?>
                <p class="pt-2 text-center text-xs text-muted"><?= e($org['phone'] ?? '') ?> · <?= e($org['hours'] ?? '') ?></p>
            </div>
        </aside>
    </div>
    </template>
</header>
