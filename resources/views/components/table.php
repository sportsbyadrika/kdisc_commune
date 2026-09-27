<?php
/**
 * Data table with empty state. Cell values are escaped unless the column has 'html' => true
 * (then 'render' must return trusted, already-escaped HTML).
 *
 *   <?= $this->component('table', [
 *       'columns' => [
 *           ['key' => 'code', 'label' => 'Seat'],
 *           ['key' => 'status', 'label' => 'Status', 'html' => true, 'render' => fn ($row) => $this->component('badge', [...])],
 *           ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'render' => fn ($row) => money($row['amount'])],
 *       ],
 *       'rows' => $rows, 'empty' => 'No bookings yet.']) ?>
 *
 * @var list<array{key: string, label: string, html?: bool, render?: callable, align?: string, class?: string}> $columns
 * @var list<array<string, mixed>> $rows
 * @var string|null $empty
 * @var string|null $caption
 */
?>
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table">
            <?php if (!empty($caption)): ?><caption class="sr-only"><?= e($caption) ?></caption><?php endif ?>
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th scope="col" class="<?= ($col['align'] ?? '') === 'right' ? '!text-right' : '' ?>"><?= e($col['label']) ?></th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-white">
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $col):
                            $value = isset($col['render']) ? ($col['render'])($row) : ($row[$col['key']] ?? '');
                        ?>
                            <td class="<?= e(class_names($col['class'] ?? '', ['text-right tabular-nums' => ($col['align'] ?? '') === 'right'])) ?>"><?= !empty($col['html']) ? $value : e($value) ?></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($rows === []): ?>
        <div class="px-6 py-12 text-center text-sm text-muted"><?= icon('inbox', 'mx-auto mb-3 size-8 text-muted/60') ?><?= e($empty ?? 'Nothing to show yet.') ?></div>
    <?php endif ?>
</div>
