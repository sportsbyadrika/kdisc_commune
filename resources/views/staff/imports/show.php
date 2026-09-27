<?php
/**
 * One upload: masked preview with per-row errors + confirm / discard, or the result after import.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $batch
 * @var App\Services\Imports\Importer $importer
 * @var array<string, mixed>|null $preview
 * @var list<array{row: int, values: array<string, string>, errors: array<string, string>, note: string}> $rows
 * @var int $shownTotal
 * @var string $tab
 * @var array<string, mixed> $summary
 * @var int $minutesLeft
 * @var bool $hasReport
 */
$this->layout('layouts/staff', [
    'subtitle' => $batch['original_name'] . ' · uploaded ' . format_date((string) $batch['created_at'], 'd M Y, H:i') . ' by ' . ($batch['created_by_name'] ?? '—'),
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Bulk upload', url('staff.imports.index')], ['#' . $batch['id']]],
]);
$cols = $importer->columns();
$pending = $batch['status'] === 'validated' && $preview !== null;
$total = (int) $batch['total_rows'];
$valid = (int) $batch['valid_rows'];
$bad = (int) $batch['error_rows'];
?>
<?php $this->start('actions') ?>
<?php if ($hasReport): ?><a class="btn btn-outline" href="<?= e(url('staff.imports.errors', ['id' => $batch['id']])) ?>"><?= icon('file-warning', 'size-4 text-red-600') ?>Error report (.xlsx)</a><?php endif ?>
<a class="btn btn-ghost" href="<?= e(url('staff.imports.template', ['type' => $batch['type']])) ?>"><?= icon('download', 'size-4') ?>Template</a>
<?php $this->stop() ?>

<div class="grid grid-cols-1 gap-3 min-[480px]:grid-cols-3 sm:gap-4 <?= $pending ? '' : 'lg:grid-cols-4' ?> [&>*]:min-w-0">
    <?= $this->component('stat', ['label' => 'Rows in the file', 'value' => $total, 'icon' => 'sheet', 'tone' => 'brand']) ?>
    <?= $this->component('stat', ['label' => $pending ? 'Ready to import' : 'Valid at import', 'value' => $valid, 'icon' => 'circle-check', 'tone' => 'success']) ?>
    <?= $this->component('stat', ['label' => 'With errors', 'value' => $bad, 'icon' => 'circle-alert', 'tone' => $bad > 0 ? 'danger' : 'success', 'hint' => $bad > 0 ? 'never imported — see the error report' : 'none']) ?>
    <?php if (!$pending): ?><?= $this->component('stat', ['label' => 'Imported', 'value' => (int) $batch['imported_rows'], 'icon' => 'check-check', 'tone' => 'info', 'hint' => ((int) $batch['failed_rows'] > 0 ? $batch['failed_rows'] . ' failed during import · ' : '') . ((int) $batch['invites_sent'] > 0 ? $batch['invites_sent'] . ' invites sent' : ($batch['imported_at'] ? 'on ' . format_date((string) $batch['imported_at'], 'd M, H:i') : ''))]) ?><?php endif ?>
</div>

<?php if ($pending): ?>
    <form method="post" action="<?= e(url('staff.imports.confirm', ['id' => $batch['id']])) ?>" class="card card-body mt-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between" x-data="{ mode: 'per_row' }">
        <?= csrf_field() ?>
        <div class="space-y-2">
            <p class="font-semibold">How should the <?= (int) $valid ?> valid row<?= $valid === 1 ? '' : 's' ?> be imported?</p>
            <div class="flex flex-col gap-2 text-sm sm:flex-row sm:gap-5">
                <label class="inline-flex items-start gap-2"><input type="radio" name="mode" value="per_row" x-model="mode" class="mt-0.5"> <span><strong>Row by row</strong> — each valid row is saved on its own; rows with errors are skipped.</span></label>
                <label class="inline-flex items-start gap-2"><input type="radio" name="mode" value="all_or_nothing" x-model="mode" class="mt-0.5"> <span><strong>All or nothing</strong> — import only if every row is valid and succeeds.</span></label>
            </div>
            <?php if ($importer->invites()): ?>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="send_invites" value="1"> Email a portal invite (set-password link) to every imported visitor</label>
            <?php endif ?>
            <p class="text-xs text-muted"><?= icon('clock', 'mr-1 inline size-3.5 align-[-2px]') ?>Rows are checked again when you confirm. The uploaded file is deleted after import, or in <?= (int) $minutesLeft ?> minutes if not confirmed.</p>
        </div>
        <div class="flex shrink-0 gap-2">
            <button class="btn btn-brand" <?= $valid === 0 ? 'disabled' : '' ?> :disabled="<?= $valid === 0 ? 'true' : 'false' ?> || (mode === 'all_or_nothing' && <?= $bad > 0 ? 'true' : 'false' ?>)"><?= icon('check-check', 'size-4') ?>Import <?= (int) $valid ?> row<?= $valid === 1 ? '' : 's' ?></button>
            <button class="btn btn-outline" formaction="<?= e(url('staff.imports.discard', ['id' => $batch['id']])) ?>" formnovalidate><?= icon('trash-2', 'size-4') ?>Discard</button>
        </div>
    </form>
    <?php if (!empty($preview['unknown'])): ?><?= $this->component('alert', ['tone' => 'info', 'class' => 'mt-4', 'message' => 'Ignored columns not in the template: ' . implode(', ', $preview['unknown']) . '.']) ?><?php endif ?>
    <?php if (!empty($preview['truncated'])): ?><?= $this->component('alert', ['tone' => 'warning', 'class' => 'mt-4', 'message' => 'Only the first ' . (int) $preview['max_rows'] . ' rows were read — split the file and upload the rest separately.']) ?><?php endif ?>

    <nav class="mt-6 mb-3 flex flex-wrap gap-2" aria-label="Preview rows">
        <?php foreach (['errors' => ['With errors', $bad], 'valid' => ['Valid', $valid], 'all' => ['All rows', $total]] as $key => [$label, $count]): $on = $key === $tab; ?>
            <a href="<?= e(url('staff.imports.show', ['id' => $batch['id'], 'tab' => $key])) ?>" class="chip <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span class="<?= $on ? 'text-white/70' : 'text-muted' ?>"><?= (int) $count ?></span></a>
        <?php endforeach ?>
    </nav>
    <?php if ($rows === []): ?>
        <?= $this->component('empty', ['icon' => $tab === 'errors' ? 'circle-check' : 'inbox', 'title' => $tab === 'errors' ? 'No errors' : 'No rows', 'text' => $tab === 'errors' ? 'Every row passed the checks.' : 'Nothing to show on this tab.']) ?>
    <?php else: ?>
        <div class="card overflow-hidden"><div class="max-h-[70vh] overflow-auto">
            <table class="table text-sm">
                <thead class="sticky top-0 z-10"><tr><th scope="col">Row</th><th scope="col">Check</th><?php foreach ($cols as $c): ?><th scope="col" class="whitespace-nowrap"><?= e($c->label()) ?></th><?php endforeach ?></tr></thead>
                <tbody class="divide-y divide-line bg-white">
                <?php foreach ($rows as $r): $ok = $r['errors'] === []; ?>
                    <tr class="<?= $ok ? '' : 'import-err' ?> align-top">
                        <td class="font-mono text-xs"><?= (int) $r['row'] ?></td>
                        <td class="min-w-56">
                            <?php if ($ok): ?>
                                <span class="inline-flex items-start gap-1.5 text-emerald-700"><?= icon('circle-check', 'mt-0.5 size-4 shrink-0') ?><span class="text-xs text-ink/80"><?= e($r['note'] !== '' ? $r['note'] : 'Ready') ?></span></span>
                            <?php else: ?>
                                <ul class="space-y-1 text-xs text-red-800">
                                    <?php foreach ($r['errors'] as $k => $m): $col = $importer->column((string) $k); ?>
                                        <li class="flex gap-1.5"><?= icon('circle-alert', 'mt-0.5 size-3.5 shrink-0') ?><span><?php if ($col !== null): ?><strong><?= e($col->header) ?>:</strong> <?php endif ?><?= e($m) ?></span></li>
                                    <?php endforeach ?>
                                </ul>
                            <?php endif ?>
                        </td>
                        <?php foreach ($cols as $c): $err = isset($r['errors'][$c->key]); ?>
                            <td class="<?= e(class_names('max-w-60 truncate', ['is-bad' => $err, 'font-mono text-xs' => $c->type === 'id'])) ?>" title="<?= e($r['values'][$c->key] ?? '') ?>"><?= ($r['values'][$c->key] ?? '') !== '' ? e($r['values'][$c->key]) : '<span class="text-muted">—</span>' ?></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div></div>
        <?php if ($shownTotal > count($rows)): ?><p class="mt-2 text-sm text-muted">Showing the first <?= count($rows) ?> of <?= (int) $shownTotal ?> rows.</p><?php endif ?>
    <?php endif ?>
<?php else: ?>
    <section class="card card-body mt-6">
        <h2 class="text-lg font-bold">Result</h2>
        <?php if (!empty($summary['message'])): ?><?= $this->component('alert', ['tone' => 'warning', 'class' => 'mt-3', 'message' => (string) $summary['message']]) ?><?php endif ?>
        <?php if (in_array($batch['status'], ['expired', 'discarded'], true)): ?>
            <p class="mt-2 text-sm text-muted">This upload was <?= e($batch['status']) ?> — its file has been deleted. Upload the workbook again to import it.</p>
        <?php elseif (!empty($summary['refs'])): ?>
            <p class="mt-1 text-sm text-muted">Mode: <?= $batch['mode'] === 'all_or_nothing' ? 'all or nothing' : 'row by row' ?>. Created or updated:</p>
            <ul class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($summary['refs'] as $row => $ref): ?>
                    <li class="flex items-center gap-2 rounded-xl bg-surface px-3 py-2 text-sm"><span class="font-mono text-xs text-muted">row <?= (int) $row ?></span><span class="truncate font-semibold"><?= e((string) $ref) ?></span></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
        <?php if (!empty($summary['failures'])): ?>
            <h3 class="mt-5 font-bold text-red-700">Failed during import</h3>
            <ul class="mt-2 space-y-1 text-sm">
                <?php foreach ($summary['failures'] as $row => $m): ?><li><span class="font-mono text-xs text-muted">row <?= (int) $row ?></span> — <?= e((string) $m) ?></li><?php endforeach ?>
            </ul>
        <?php endif ?>
        <?php if ($hasReport): ?><p class="mt-5 text-sm">Download the <a class="font-semibold text-red-700 hover:underline" href="<?= e(url('staff.imports.errors', ['id' => $batch['id']])) ?>">error report</a> — the rows that were not imported with an "Error" column; fix them and upload that file again.</p><?php endif ?>
    </section>
<?php endif ?>
