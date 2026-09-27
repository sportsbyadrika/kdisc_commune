<?php
/**
 * Staff top bar: mobile menu toggle, search placeholder, public-site link, user menu with sign-out.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed>|null $user
 * @var App\Enums\StaffRole|null $role
 */
$initials = '';
foreach (array_slice(explode(' ', (string) ($user['name'] ?? '')), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
?>
<header class="sticky top-0 z-30 border-b border-line/70 bg-white/90 backdrop-blur">
    <div class="container-page flex h-[72px] items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <button type="button" class="btn btn-ghost btn-icon lg:hidden" @click="$store.sidebar.mobileOpen = true" aria-label="Open menu"><?= icon('menu', 'size-6') ?></button>
            <label class="relative hidden md:block">
                <span class="sr-only">Search visitors</span>
                <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-muted"><?= icon('search', 'size-[18px]') ?></span>
                <input type="search" disabled placeholder="Search visitors — coming soon" class="input w-80 rounded-full !bg-surface pl-10">
            </label>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= e(url('home')) ?>" class="btn btn-ghost hidden sm:inline-flex" target="_blank" rel="noopener"><?= icon('external-link', 'size-4') ?> Public site</a>
            <button type="button" class="btn btn-ghost btn-icon relative" aria-label="Notifications"><?= icon('bell', 'size-5') ?></button>
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                <button type="button" class="flex items-center gap-3 rounded-full py-1 pr-3 pl-1 transition hover:bg-surface-2" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true">
                    <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white"><?= e($initials) ?></span>
                    <span class="hidden text-left leading-tight sm:block">
                        <span class="block text-sm font-semibold"><?= e($user['name'] ?? '') ?></span>
                        <span class="block text-xs text-muted"><?= e($role?->label() ?? '') ?></span>
                    </span>
                    <?= icon('chevron-down', 'size-4 text-muted') ?>
                </button>
                <div x-cloak x-show="open" x-transition.opacity.scale.origin.top.right class="absolute right-0 mt-2 w-64 rounded-2xl border border-line bg-white p-2 shadow-[var(--shadow-card-hover)]">
                    <div class="px-3 py-2">
                        <p class="text-sm font-semibold"><?= e($user['name'] ?? '') ?></p>
                        <p class="truncate text-xs text-muted"><?= e($user['email'] ?? '') ?></p>
                    </div>
                    <div class="my-1 border-t border-line"></div>
                    <form method="post" action="<?= e(url('staff.logout')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"><?= icon('log-out', 'size-4') ?> Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
