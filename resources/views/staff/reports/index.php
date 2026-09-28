<?php
/**
 * Reports hub (/staff/reports): the report definitions this role may open, grouped (ReportRegistry::hub()).
 *
 * @var App\Core\Template $this
 * @var array<string, list<App\Services\Reports\Report>> $hub
 */
use App\Services\Reports\ReportRegistry;

$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Reports']]]);
?>
<?php foreach ($hub as $group => $reports): if ($reports === []) { continue; } [$label, $ic] = ReportRegistry::GROUPS[$group] ?? [ucfirst($group), 'chart-column']; ?>
    <section class="mb-8">
        <h2 class="mb-3 flex items-center gap-2 text-sm font-bold tracking-[0.14em] text-muted uppercase"><?= icon($ic, 'size-4') ?><?= e($label) ?></h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($reports as $r): ?>
                <article class="card card-hover flex flex-col p-5">
                    <a href="<?= e(url('staff.reports.show', ['key' => $r->key()])) ?>" class="flex flex-1 items-start gap-4">
                        <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-700"><?= icon($r->icon(), 'size-5') ?></span>
                        <span class="min-w-0">
                            <span class="block font-display text-base font-bold text-ink"><?= e($r->title()) ?></span>
                            <span class="mt-1 block text-sm text-muted"><?= e($r->description()) ?></span>
                        </span>
                    </a>
                    <div class="mt-4 flex gap-2 border-t border-line pt-3 text-sm">
                        <a class="font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.reports.show', ['key' => $r->key()])) ?>">Open</a>
                        <span class="text-line">|</span>
                        <a class="inline-flex items-center gap-1 font-semibold text-ink/70 hover:text-ink" href="<?= e(url('staff.reports.xlsx', ['key' => $r->key()])) ?>"><?= icon('file-spreadsheet', 'size-4') ?>XLSX</a>
                        <a class="inline-flex items-center gap-1 font-semibold text-ink/70 hover:text-ink" href="<?= e(url('staff.reports.pdf', ['key' => $r->key()])) ?>" target="_blank" rel="noopener"><?= icon('file-down', 'size-4') ?>PDF</a>
                    </div>
                </article>
            <?php endforeach ?>
        </div>
    </section>
<?php endforeach ?>
<p class="text-sm text-muted">Visitor, booking, payment and invoice lists export from their own pages with the filters you have applied there.</p>
