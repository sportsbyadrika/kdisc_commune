<?php
/**
 * Audit log list with filters (AuditController::index).
 *
 * @var App\Core\Template $this
 * @var array<string, string> $filters
 * @var array{actions: list<string>, entities: list<string>, users: array<string, string>} $options
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 */
$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Audit log']]]);
$actor = static function (array $r): string {
    return match ($r['actor_type']) {
        'staff' => (string) ($r['staff_name'] ?? 'Staff #' . $r['actor_id']),
        'account' => 'Visitor account #' . $r['actor_id'],
        default => 'System',
    };
};
?>
<form method="get" class="card mb-5 grid gap-3 p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-4 xl:grid-cols-[repeat(6,minmax(0,1fr))_auto]" aria-label="Audit filters">
    <?= $this->component('select', ['name' => 'user', 'label' => 'Who', 'value' => $filters['user'], 'options' => $options['users'], 'placeholder' => 'Anyone']) ?>
    <?= $this->component('select', ['name' => 'action', 'label' => 'Action', 'value' => $filters['action'], 'options' => array_combine($options['actions'], array_map(static fn ($a) => str_ends_with($a, '.') ? $a . '*' : $a, $options['actions'])), 'placeholder' => 'Any action']) ?>
    <?= $this->component('select', ['name' => 'entity', 'label' => 'Record type', 'value' => $filters['entity'], 'options' => array_combine($options['entities'], $options['entities']), 'placeholder' => 'Any']) ?>
    <?= $this->component('input', ['name' => 'entity_id', 'label' => 'Record id', 'value' => $filters['entity_id'], 'attrs' => ['inputmode' => 'numeric']]) ?>
    <?= $this->component('input', ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => $filters['from']]) ?>
    <?= $this->component('input', ['name' => 'to', 'label' => 'To', 'type' => 'date', 'value' => $filters['to']]) ?>
    <div class="flex items-end gap-2">
        <button class="btn btn-brand"><?= icon('list-filter', 'size-4') ?>Filter</button>
        <?php if (array_filter($filters) !== []): ?><a class="btn btn-ghost" href="<?= e(url('staff.audit.index')) ?>">Clear</a><?php endif ?>
    </div>
</form>

<?php if ($result['rows'] === []): ?>
    <?= $this->component('empty', ['icon' => 'history', 'title' => 'No entries', 'text' => 'Nothing matches these filters.']) ?>
<?php else: ?>
    <div class="card overflow-hidden"><div class="overflow-x-auto">
        <table class="table text-sm">
            <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Reason / details</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
            <tbody class="divide-y divide-line bg-white">
            <?php foreach ($result['rows'] as $r): ?>
                <tr>
                    <td class="whitespace-nowrap"><?= e(format_date((string) $r['created_at'], 'd M Y')) ?><span class="block text-xs text-muted"><?= e(format_date((string) $r['created_at'], 'H:i:s')) ?></span></td>
                    <td class="whitespace-nowrap"><span class="font-semibold"><?= e($actor($r)) ?></span><?php if ($r['staff_role']): ?><span class="block text-xs text-muted"><?= e(App\Enums\StaffRole::tryFrom((string) $r['staff_role'])?->label() ?? '') ?></span><?php endif ?></td>
                    <td><span class="rounded-md bg-surface-2 px-1.5 py-0.5 font-mono text-xs"><?= e($r['action']) ?></span></td>
                    <td class="whitespace-nowrap"><?= $r['entity_type'] !== null ? e($r['entity_type'] . ($r['entity_id'] !== null ? ' #' . $r['entity_id'] : '')) : '<span class="text-muted">—</span>' ?></td>
                    <td class="max-w-md truncate text-muted" title="<?= e((string) ($r['reason'] ?? '')) ?>"><?= e((string) ($r['reason'] ?? '')) ?: e(mb_substr((string) ($r['new_values'] ?? ''), 0, 120)) ?></td>
                    <td class="text-right"><a class="font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.audit.show', ['id' => $r['id']])) ?>">Diff</a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div></div>
    <div class="mt-4"><?= $this->component('pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'], 'perPage' => $result['per_page'], 'route' => 'staff.audit.index', 'query' => $filters]) ?></div>
<?php endif ?>
