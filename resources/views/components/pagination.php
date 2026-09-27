<?php
/**
 * Pager for list pages. Keeps the current filters in the query string.
 *   <?= $this->component('pagination', ['page' => 2, 'pages' => 7, 'total' => 131, 'route' => 'staff.visitors.index', 'query' => $filters]) ?>
 *
 * @var int $page
 * @var int $pages
 * @var int|null $total
 * @var string $route
 * @var array<string, scalar|null>|null $query
 * @var int|null $perPage
 */
$query = array_filter($query ?? [], static fn ($v) => $v !== null && $v !== '');
$link = static fn (int $p): string => url($route, $query + ($p > 1 ? ['page' => $p] : []));
$window = array_values(array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn ($p) => $p >= 1 && $p <= $pages)));
sort($window);
$from = $total !== null && $total > 0 ? (($page - 1) * ($perPage ?? 20)) + 1 : 0;
$to = $total !== null ? min($total, $page * ($perPage ?? 20)) : 0;
?>
<nav class="flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Pagination">
    <p class="text-sm text-muted"><?php if ($total !== null): ?>Showing <span class="font-semibold text-ink"><?= $from ?>–<?= $to ?></span> of <span class="font-semibold text-ink"><?= (int) $total ?></span><?php endif ?></p>
    <?php if ($pages > 1): ?>
        <div class="flex items-center gap-1">
            <a href="<?= e($link(max(1, $page - 1))) ?>" class="<?= e(class_names('btn btn-ghost btn-icon', ['pointer-events-none opacity-40' => $page <= 1])) ?>" aria-label="Previous page"><?= icon('chevron-left', 'size-4') ?></a>
            <?php $prev = 0; foreach ($window as $p): ?>
                <?php if ($p - $prev > 1): ?><span class="px-1 text-muted">…</span><?php endif ?>
                <a href="<?= e($link($p)) ?>" class="<?= e(class_names('grid size-10 place-items-center rounded-full text-sm font-semibold transition', $p === $page ? 'bg-brand-900 text-white' : 'text-ink/80 hover:bg-surface-2')) ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
            <?php $prev = $p; endforeach ?>
            <a href="<?= e($link(min($pages, $page + 1))) ?>" class="<?= e(class_names('btn btn-ghost btn-icon', ['pointer-events-none opacity-40' => $page >= $pages])) ?>" aria-label="Next page"><?= icon('chevron-right', 'size-4') ?></a>
        </div>
    <?php endif ?>
</nav>
