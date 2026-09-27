<?php
/**
 * Filter chips (events-category style). Two modes:
 *  - Links:   items with 'href'; active chip = $active value.
 *  - Alpine:  pass 'model' => 'filter' and wrap in x-data="{ filter: 'all' }"; chips set the variable.
 *
 *   <?= $this->component('chips', ['model' => 'filter', 'items' => [
 *         ['value' => 'all', 'label' => 'All'], ['value' => 'FLEXI', 'label' => 'Flexi', 'icon' => 'armchair', 'count' => 42]]]) ?>
 *
 * @var list<array{value: string, label: string, href?: string, icon?: string, count?: int}> $items
 * @var string|null $active
 * @var string|null $model  Alpine variable name
 * @var string|null $label  aria-label for the group
 * @var string|null $class
 */
?>
<div class="<?= e(class_names('-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0', $class ?? '')) ?>" role="<?= isset($model) ? 'radiogroup' : 'navigation' ?>" aria-label="<?= e($label ?? 'Filter') ?>">
    <?php foreach ($items as $item):
        $inner = (isset($item['icon']) ? icon($item['icon'], 'size-4') : '') . e($item['label'])
            . (isset($item['count']) ? '<span class="ml-0.5 rounded-full bg-current/10 px-1.5 text-[11px] leading-5">' . (int) $item['count'] . '</span>' : '');
    ?>
        <?php if (isset($model)): ?>
            <button type="button" role="radio" class="chip shrink-0"
                    :class="<?= e($model) ?> === '<?= e($item['value']) ?>' && 'chip-active'"
                    :aria-checked="(<?= e($model) ?> === '<?= e($item['value']) ?>').toString()"
                    @click="<?= e($model) ?> = '<?= e($item['value']) ?>'"><?= $inner ?></button>
        <?php else: ?>
            <a href="<?= e($item['href'] ?? '#') ?>" class="<?= e(class_names('chip shrink-0', ['chip-active' => ($active ?? null) === $item['value']])) ?>"
               <?= ($active ?? null) === $item['value'] ? 'aria-current="true"' : '' ?>><?= $inner ?></a>
        <?php endif ?>
    <?php endforeach ?>
</div>
