<?php
/**
 * Bulk upload hub: templates per type, upload form, history (ImportController::index).
 *
 * @var App\Core\Template $this
 * @var array<string, App\Services\Imports\Importer> $importers
 * @var list<array<string, mixed>> $history
 * @var int $maxMb
 * @var int $maxRows
 */
$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Bulk upload']]]);
$statusTone = ['validated' => 'warning', 'imported' => 'success', 'partial' => 'info', 'failed' => 'danger', 'expired' => 'neutral', 'discarded' => 'neutral', 'uploaded' => 'neutral'];
$statusLabel = ['validated' => 'Awaiting confirmation', 'imported' => 'Imported', 'partial' => 'Partly imported', 'failed' => 'Not imported', 'expired' => 'Expired', 'discarded' => 'Discarded', 'uploaded' => 'Uploaded'];
$fileError = errors('file');
?>
<div class="grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
    <section class="xl:col-span-2">
        <h2 class="mb-3 text-sm font-bold tracking-[0.14em] text-muted uppercase">1 · Download a template</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <?php foreach ($importers as $key => $imp): ?>
                <div class="card flex items-start gap-4 p-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-700"><?= icon($imp->icon(), 'size-5') ?></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-display font-bold"><?= e($imp->label()) ?></p>
                        <p class="mt-0.5 text-sm text-muted"><?= e($imp->description()) ?></p>
                        <a class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.imports.template', ['type' => $key])) ?>"><?= icon('download', 'size-4') ?>Template (.xlsx)</a>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-bold tracking-[0.14em] text-muted uppercase">2 · Upload the filled file</h2>
        <form method="post" action="<?= e(url('staff.imports.upload')) ?>" enctype="multipart/form-data" class="card card-body space-y-4">
            <?= csrf_field() ?>
            <?= $this->component('select', ['name' => 'type', 'label' => 'Import type', 'required' => true, 'placeholder' => 'Choose…', 'options' => array_map(static fn ($i) => $i->label(), $importers)]) ?>
            <div>
                <label for="f-file" class="label">Workbook (.xlsx) <span class="text-accent-500" aria-hidden="true">*</span></label>
                <input id="f-file" type="file" name="file" required accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="<?= e(class_names('input file:mr-3 file:rounded-full file:border-0 file:bg-brand-50 file:px-3 file:py-1 file:text-sm file:font-semibold file:text-brand-700', ['input-error' => $fileError !== null])) ?>" <?= $fileError !== null ? 'aria-invalid="true" aria-describedby="file-error"' : '' ?>>
                <?php if ($fileError !== null): ?><p id="file-error" class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e($fileError) ?></p><?php else: ?><p class="help">Up to <?= (int) $maxMb ?> MB and <?= (int) $maxRows ?> rows. Nothing is saved until you confirm the preview.</p><?php endif ?>
            </div>
            <button class="btn btn-brand w-full"><?= icon('upload', 'size-4') ?>Upload and check</button>
            <p class="flex gap-2 text-xs text-muted"><?= icon('shield-check', 'size-4 shrink-0') ?><span>The file is kept in a private folder only until you confirm or discard it (or it expires) and is then deleted. Aadhaar numbers are masked in the preview and error reports.</span></p>
        </form>
    </section>
</div>

<section class="mt-10">
    <h2 class="mb-3 text-lg font-bold">History</h2>
    <?php if ($history === []): ?>
        <?= $this->component('empty', ['icon' => 'file-spreadsheet', 'title' => 'No uploads yet', 'text' => 'Uploaded workbooks and their results appear here.']) ?>
    <?php else: ?>
        <div class="card overflow-hidden"><div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th scope="col">#</th><th scope="col">Type</th><th scope="col">File</th><th scope="col">Uploaded</th><th scope="col" class="!text-right">Rows</th><th scope="col" class="!text-right">Valid</th><th scope="col" class="!text-right">Errors</th><th scope="col" class="!text-right">Imported</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-line bg-white">
                <?php foreach ($history as $b): $imp = $importers[$b['type']] ?? null; ?>
                    <tr>
                        <td class="font-mono text-xs"><?= (int) $b['id'] ?></td>
                        <td class="font-semibold"><?= e($imp?->label() ?? $b['type']) ?></td>
                        <td class="max-w-56 truncate text-sm" title="<?= e($b['original_name']) ?>"><?= e($b['original_name']) ?></td>
                        <td class="text-sm whitespace-nowrap"><?= e(format_date((string) $b['created_at'], 'd M Y, H:i')) ?><span class="block text-xs text-muted"><?= e($b['created_by_name'] ?? '') ?></span></td>
                        <td class="text-right tabular-nums"><?= (int) $b['total_rows'] ?></td>
                        <td class="text-right tabular-nums"><?= (int) $b['valid_rows'] ?></td>
                        <td class="text-right tabular-nums <?= (int) $b['error_rows'] > 0 ? 'font-semibold text-red-700' : '' ?>"><?= (int) $b['error_rows'] ?></td>
                        <td class="text-right tabular-nums"><?= (int) $b['imported_rows'] ?></td>
                        <td><?= $this->component('badge', ['label' => $statusLabel[$b['status']] ?? $b['status'], 'tone' => $statusTone[$b['status']] ?? 'neutral']) ?></td>
                        <td class="text-right whitespace-nowrap">
                            <a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.imports.show', ['id' => $b['id']])) ?>"><?= $b['status'] === 'validated' ? 'Review' : 'View' ?></a>
                            <?php if (!empty($b['error_report_path'])): ?> · <a class="text-sm font-semibold text-red-700 hover:underline" href="<?= e(url('staff.imports.errors', ['id' => $b['id']])) ?>">Errors</a><?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div></div>
    <?php endif ?>
</section>
