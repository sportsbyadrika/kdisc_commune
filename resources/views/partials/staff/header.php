<?php
/**
 * Staff top bar: mobile menu toggle, visitor search, public-site link, notification bell (shared `staffInbox`
 * from StaffAuth: unread count + latest), user menu with sign-out.
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
            <?php if ($role?->can('visitors.view')): ?>
                <form method="get" action="<?= e(url('staff.visitors.index')) ?>" class="relative hidden md:block" role="search">
                    <label for="staff-search" class="sr-only">Search visitors</label>
                    <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-muted"><?= icon('search', 'size-[18px]') ?></span>
                    <input id="staff-search" type="search" name="q" value="<?= e(route_is('staff.visitors.index') ? (string) (App\Core\App::request()?->query('q') ?? '') : '') ?>" placeholder="Search visitors: ID, name, mobile, PAN…" class="input w-80 rounded-full !bg-surface pl-10">
                </form>
            <?php endif ?>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= e(url('home')) ?>" class="btn btn-ghost hidden sm:inline-flex" target="_blank" rel="noopener"><?= icon('external-link', 'size-4') ?> Public site</a>
            <?php $inbox = $staffInbox ?? ['unread' => 0, 'latest' => []]; $unread = (int) $inbox['unread']; ?>
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                <button type="button" class="btn btn-ghost btn-icon relative" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true" aria-label="<?= e('Notifications' . ($unread > 0 ? " ({$unread} unread)" : '')) ?>">
                    <?= icon($unread > 0 ? 'bell-ring' : 'bell', 'size-5') ?>
                    <?php if ($unread > 0): ?><span class="absolute top-1 right-1 grid h-4.5 min-w-4.5 place-items-center rounded-full bg-accent-500 px-1 text-[10px] leading-none font-bold text-white ring-2 ring-white"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif ?>
                </button>
                <div x-cloak x-show="open" x-transition.opacity.scale.origin.top.right class="fixed inset-x-3 top-[76px] z-40 rounded-2xl border border-line bg-white shadow-[var(--shadow-card-hover)] sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-96">
                    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                        <p class="text-sm font-bold">Notifications <?php if ($unread > 0): ?><span class="ml-1 rounded-full bg-accent-50 px-2 py-0.5 text-xs text-accent-600"><?= $unread ?> new</span><?php endif ?></p>
                        <?php if ($unread > 0): ?>
                            <form method="post" action="<?= e(url('staff.notifications.read_all')) ?>"><?= csrf_field() ?><button class="text-xs font-semibold text-brand-700 hover:underline">Mark all read</button></form>
                        <?php endif ?>
                    </div>
                    <?php if ($inbox['latest'] === []): ?>
                        <p class="px-4 py-8 text-center text-sm text-muted"><?= icon('inbox', 'mx-auto mb-2 size-6 text-muted/60') ?>No notifications yet.</p>
                    <?php else: ?>
                        <ul class="max-h-96 divide-y divide-line overflow-y-auto">
                            <?php foreach ($inbox['latest'] as $n): ?>
                                <li><a href="<?= e(url('staff.notifications.open', ['id' => $n['id']])) ?>" class="flex gap-3 px-4 py-3 transition hover:bg-surface <?= $n['read_at'] === null ? 'bg-brand-50/40' : '' ?>">
                                    <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full <?= $n['read_at'] === null ? 'bg-brand-600 text-white' : 'bg-surface-2 text-muted' ?>"><?= icon((string) $n['icon'], 'size-4') ?></span>
                                    <span class="min-w-0 flex-1"><span class="line-clamp-2 text-sm <?= $n['read_at'] === null ? 'font-bold' : 'font-semibold text-ink/80' ?>"><?= e($n['title']) ?></span><span class="line-clamp-2 text-xs text-muted"><?= e((string) $n['body']) ?></span><span class="mt-0.5 block text-[11px] text-muted"><?= e(format_date((string) $n['created_at'], 'd M, H:i')) ?></span></span>
                                </a></li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                    <a href="<?= e(url('staff.notifications.index')) ?>" class="block border-t border-line px-4 py-2.5 text-center text-sm font-semibold text-brand-700 hover:bg-surface">All notifications</a>
                </div>
            </div>
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
