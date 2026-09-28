<?php
/**
 * "Export" menu for list pages: XLSX / PDF of the matching report definition with the page's current filters
 * (ReportController — /staff/reports/{key}.xlsx|.pdf).
 *
 * @var App\Core\Template $this
 * @var string $key report key (visitors | bookings-list | payments | invoices)
 * @var array<string, scalar|null> $query current filters
 */
$q = array_filter($query, static fn ($v) => $v !== null && $v !== '');
?>
<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" class="btn btn-outline" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true"><?= icon('download', 'size-4') ?><span class="hidden sm:inline">Export</span><?= icon('chevron-down', 'size-4 text-muted') ?></button>
    <div x-cloak x-show="open" x-transition.opacity class="absolute right-0 z-30 mt-2 w-60 rounded-2xl border border-line bg-white p-1.5 shadow-[var(--shadow-card-hover)]">
        <a href="<?= e(url('staff.reports.xlsx', ['key' => $key] + $q)) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold hover:bg-surface"><?= icon('file-spreadsheet', 'size-4 text-emerald-700') ?>Excel (.xlsx)</a>
        <a href="<?= e(url('staff.reports.pdf', ['key' => $key] + $q)) ?>" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold hover:bg-surface"><?= icon('file-down', 'size-4 text-red-700') ?>PDF</a>
        <a href="<?= e(url('staff.reports.show', ['key' => $key] + $q)) ?>" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold hover:bg-surface"><?= icon('table-2', 'size-4 text-muted') ?>Preview all rows</a>
        <p class="px-3 pt-1 pb-1.5 text-xs text-muted">Uses the filters on this page.</p>
    </div>
</div>
