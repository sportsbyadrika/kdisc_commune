<?php
/**
 * One audit entry with an old → new diff (AuditTrail::diff()).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $entry
 * @var list<array{key: string, old: ?string, new: ?string, state: string}> $diff
 */
$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Audit log', url('staff.audit.index')], ['#' . $entry['id']]]]);
$tone = ['changed' => 'bg-amber-50', 'added' => 'bg-emerald-50', 'removed' => 'bg-red-50', 'same' => ''];
$badge = ['changed' => ['Changed', 'warning'], 'added' => ['Added', 'success'], 'removed' => ['Removed', 'danger'], 'same' => ['Unchanged', 'neutral']];
$who = match ($entry['actor_type']) {
    'staff' => ($entry['staff_name'] ?? 'Staff #' . $entry['actor_id']) . ($entry['staff_email'] ? ' · ' . $entry['staff_email'] : ''),
    'account' => 'Visitor account #' . $entry['actor_id'],
    default => 'System',
};
$recordUrl = $entry['entity_type'] === 'customer' && $entry['entity_id'] !== null ? url('staff.visitors.show', ['ref' => (string) $entry['entity_id']]) : null;
?>
<div class="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
    <section class="card card-body">
        <dl class="space-y-3 text-sm">
            <div><dt class="text-muted">Action</dt><dd class="mt-0.5 font-mono font-semibold"><?= e($entry['action']) ?></dd></div>
            <div><dt class="text-muted">When</dt><dd class="mt-0.5 font-semibold"><?= e(format_date((string) $entry['created_at'], 'd M Y, H:i:s')) ?></dd></div>
            <div><dt class="text-muted">Who</dt><dd class="mt-0.5 font-semibold"><?= e($who) ?></dd></div>
            <div><dt class="text-muted">Record</dt><dd class="mt-0.5 font-semibold"><?php if ($entry['entity_type'] !== null): ?><?= $recordUrl !== null ? '<a class="text-brand-700 hover:underline" href="' . e($recordUrl) . '">' : '' ?><?= e($entry['entity_type'] . ($entry['entity_id'] !== null ? ' #' . $entry['entity_id'] : '')) ?><?= $recordUrl !== null ? '</a>' : '' ?><?php else: ?>—<?php endif ?></dd></div>
            <?php if (($entry['reason'] ?? '') !== ''): ?><div><dt class="text-muted">Reason</dt><dd class="mt-0.5"><?= e($entry['reason']) ?></dd></div><?php endif ?>
            <div><dt class="text-muted">From</dt><dd class="mt-0.5 font-mono text-xs"><?= e((string) ($entry['ip'] ?? '—')) ?></dd><dd class="mt-0.5 truncate text-xs text-muted" title="<?= e((string) ($entry['user_agent'] ?? '')) ?>"><?= e((string) ($entry['user_agent'] ?? '')) ?></dd></div>
        </dl>
    </section>
    <section class="card overflow-hidden lg:col-span-2">
        <div class="card-body !pb-3"><h2 class="text-lg font-bold">What changed</h2><p class="text-sm text-muted">Old values → new values, key by key.</p></div>
        <?php if ($diff === []): ?>
            <p class="px-6 pb-6 text-sm text-muted">No values were recorded for this action.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="table text-sm">
                    <thead><tr><th scope="col">Field</th><th scope="col">Old</th><th scope="col">New</th><th scope="col"><span class="sr-only">Change</span></th></tr></thead>
                    <tbody class="divide-y divide-line bg-white">
                    <?php foreach ($diff as $d): ?>
                        <tr class="<?= $tone[$d['state']] ?>">
                            <td class="font-mono text-xs font-semibold"><?= e($d['key']) ?></td>
                            <td class="max-w-xs break-words font-mono text-xs <?= $d['state'] === 'changed' || $d['state'] === 'removed' ? 'text-red-800 line-through decoration-red-300' : 'text-muted' ?>"><?= $d['old'] !== null ? e($d['old']) : '<span class="text-muted no-underline">—</span>' ?></td>
                            <td class="max-w-xs break-words font-mono text-xs <?= $d['state'] === 'changed' || $d['state'] === 'added' ? 'font-semibold text-emerald-800' : '' ?>"><?= $d['new'] !== null ? e($d['new']) : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-right"><?= $this->component('badge', ['label' => $badge[$d['state']][0], 'tone' => $badge[$d['state']][1]]) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
</div>
