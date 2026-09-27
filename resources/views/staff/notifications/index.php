<?php
/**
 * Staff notification inbox (NotificationController::index).
 *
 * @var App\Core\Template $this
 * @var bool $unreadOnly
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var int $unread
 */
$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Notifications']]]);
?>
<?php if ($unread > 0): ?>
<?php $this->start('actions') ?>
<form method="post" action="<?= e(url('staff.notifications.read_all')) ?>"><?= csrf_field() ?><button class="btn btn-outline"><?= icon('check-check', 'size-4') ?>Mark all read</button></form>
<?php $this->stop() ?>
<?php endif ?>
<nav class="mb-4 flex gap-2" aria-label="Filter notifications">
    <a href="<?= e(url('staff.notifications.index')) ?>" class="chip <?= !$unreadOnly ? 'chip-active' : '' ?>" <?= !$unreadOnly ? 'aria-current="page"' : '' ?>>All</a>
    <a href="<?= e(url('staff.notifications.index', ['show' => 'unread'])) ?>" class="chip <?= $unreadOnly ? 'chip-active' : '' ?>" <?= $unreadOnly ? 'aria-current="page"' : '' ?>>Unread <span class="<?= $unreadOnly ? 'text-white/70' : 'text-muted' ?>"><?= (int) $unread ?></span></a>
</nav>
<?php if ($result['rows'] === []): ?>
    <?= $this->component('empty', ['icon' => 'inbox', 'title' => $unreadOnly ? 'All caught up' : 'No notifications', 'text' => $unreadOnly ? 'You have read everything.' : 'Booking changes and Finance queries will appear here.']) ?>
<?php else: ?>
    <ul class="card divide-y divide-line overflow-hidden">
        <?php foreach ($result['rows'] as $n): $new = $n['read_at'] === null; ?>
            <li class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:px-5 <?= $new ? 'bg-brand-50/40' : '' ?>">
                <span class="grid size-10 shrink-0 place-items-center rounded-full <?= $new ? 'bg-brand-600 text-white' : 'bg-surface-2 text-muted' ?>"><?= icon((string) $n['icon'], 'size-5') ?></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm <?= $new ? 'font-bold' : 'font-semibold text-ink/80' ?>"><?= e($n['title']) ?><?php if ($new): ?> <span class="sr-only">(unread)</span><?php endif ?></p>
                    <p class="mt-0.5 text-sm text-muted"><?= e((string) $n['body']) ?></p>
                    <p class="mt-1 text-xs text-muted"><?= e(format_date((string) $n['created_at'], 'd M Y, H:i')) ?><?= !$new ? ' · read ' . e(format_date((string) $n['read_at'], 'd M, H:i')) : '' ?></p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <?php if ($n['url'] !== null): ?><a class="btn btn-outline btn-sm" href="<?= e(url('staff.notifications.open', ['id' => $n['id']])) ?>">Open</a><?php endif ?>
                    <?php if ($new): ?><form method="post" action="<?= e(url('staff.notifications.read', ['id' => $n['id']])) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= icon('check', 'size-4') ?>Mark read</button></form><?php endif ?>
                </div>
            </li>
        <?php endforeach ?>
    </ul>
    <div class="mt-4"><?= $this->component('pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'], 'perPage' => $result['per_page'], 'route' => 'staff.notifications.index', 'query' => $unreadOnly ? ['show' => 'unread'] : []]) ?></div>
<?php endif ?>
