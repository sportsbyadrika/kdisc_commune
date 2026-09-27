<?php
/**
 * @var list<array{0: string, 1?: string|null}> $items [label, href] — last item is the current page
 * @var bool|null $inverse light text on dark backgrounds
 */
$inverse = !empty($inverse);
?>
<nav aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-1.5 text-sm <?= $inverse ? 'text-white/70' : 'text-muted' ?>">
        <?php foreach ($items as $i => $item): $last = $i === count($items) - 1; ?>
            <li class="inline-flex items-center gap-1.5">
                <?php if ($i > 0): ?><?= icon('chevron-right', 'size-3.5 opacity-60') ?><?php endif ?>
                <?php if (!$last && !empty($item[1])): ?>
                    <a href="<?= e($item[1]) ?>" class="<?= $inverse ? 'hover:text-white' : 'hover:text-ink' ?>"><?= e($item[0]) ?></a>
                <?php else: ?>
                    <span class="font-semibold <?= $inverse ? 'text-white' : 'text-ink' ?>" <?= $last ? 'aria-current="page"' : '' ?>><?= e($item[0]) ?></span>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>
