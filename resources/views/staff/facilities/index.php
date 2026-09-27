<?php
/**
 * Facility master (spec 5.3): included amenities, chargeable add-ons, landmarks.
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $facilities FacilityService::all()
 */
$this->layout('layouts/staff', [
    'title' => 'Facilities',
    'subtitle' => 'Amenities included with every seat, chargeable add-ons (priced in the booking drawer) and map landmarks.',
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Facilities']],
]);
?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.layout.index')) ?>" class="btn btn-outline"><?= icon('pen-tool', 'size-4') ?>Place on plans</a>
<a href="<?= e(url('staff.facilities.create')) ?>" class="btn btn-brand" data-test="facility-new"><?= icon('plus', 'size-4') ?>New facility</a>
<?php $this->stop() ?>

<?php foreach (App\Enums\FacilityKind::cases() as $kind):
    $rows = array_values(array_filter($facilities, static fn (array $f) => $f['kind'] === $kind->value));
    $hint = ['included' => 'Free with every booking — shown in seat tooltips.', 'addon' => 'Visitors toggle these in the selection drawer; GST per facility; stock limits availability.', 'landmark' => 'Shown on the map only (entry, lift, fire exit…).'][$kind->value];
?>
    <section class="card mb-6 overflow-hidden">
        <header class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-5 py-3.5">
            <h2 class="flex items-center gap-2 text-lg font-extrabold"><?= $this->component('badge', ['label' => $kind->label(), 'tone' => $kind->tone()]) ?><span class="text-sm font-semibold text-muted"><?= count($rows) ?></span></h2>
            <p class="text-xs text-muted"><?= e($hint) ?></p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface text-left text-[11px] font-bold tracking-wide text-muted uppercase">
                    <tr><th class="px-5 py-2.5">Facility</th><?php if ($kind === App\Enums\FacilityKind::Addon): ?><th class="px-4 py-2.5 text-right">Price</th><th class="px-4 py-2.5">GST</th><th class="px-4 py-2.5">Stock</th><?php endif ?><th class="px-4 py-2.5">On plans</th><th class="px-4 py-2.5">Status</th><th class="px-4 py-2.5"></th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <?php foreach ($rows as $f): ?>
                        <tr class="<?= $f['is_active'] ? '' : 'bg-surface/60 text-ink/55' ?>" data-test="facility-<?= e((string) $f['code']) ?>">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full text-lg ring-1 <?= $kind === App\Enums\FacilityKind::Landmark ? 'bg-brand-950 text-white ring-brand-950' : ($kind === App\Enums\FacilityKind::Addon ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200') ?>"><?= icon((string) ($f['icon'] ?: 'info'), 'size-5') ?></span>
                                    <div class="min-w-0"><p class="font-bold"><?= e((string) ($f['emoji'] ?? '')) ?> <?= e((string) $f['name']) ?> <span class="ml-1 font-mono text-[11px] font-semibold text-muted"><?= e((string) $f['code']) ?></span></p><p class="truncate text-xs text-muted"><?= e((string) ($f['description'] ?? '')) ?></p></div>
                                </div>
                            </td>
                            <?php if ($kind === App\Enums\FacilityKind::Addon): ?>
                                <td class="px-4 py-3 text-right font-bold whitespace-nowrap tabular-nums"><?= e(money((float) $f['price'])) ?><span class="text-xs font-medium text-muted"> <?= e(App\Enums\FacilityUnit::tryFrom((string) $f['unit'])?->label() ?? '') ?></span></td>
                                <td class="px-4 py-3"><?= e((string) (float) $f['gst_rate']) ?>%</td>
                                <td class="px-4 py-3"><?= $f['stock_qty'] !== null ? (int) $f['stock_qty'] : '<span class="text-muted">∞</span>' ?></td>
                            <?php endif ?>
                            <td class="px-4 py-3 text-xs"><?= (int) $f['placed'] ?> placed<?= (int) $f['booked'] ? ' · ' . (int) $f['booked'] . ' booked' : '' ?></td>
                            <td class="px-4 py-3">
                                <form method="post" action="<?= e(url('staff.facilities.toggle', ['id' => (int) $f['id']])) ?>"><?= csrf_field() ?>
                                    <button class="badge <?= $f['is_active'] ? 'badge-success' : 'badge-neutral' ?> cursor-pointer" title="Click to <?= $f['is_active'] ? 'deactivate' : 'activate' ?>"><?= $f['is_active'] ? 'Active' : 'Inactive' ?></button></form>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="<?= e(url('staff.facilities.edit', ['id' => (int) $f['id']])) ?>" class="btn btn-ghost btn-sm"><?= icon('pencil', 'size-4') ?>Edit</a>
                                <form method="post" action="<?= e(url('staff.facilities.destroy', ['id' => (int) $f['id']])) ?>" class="inline" x-data @submit="if (!confirm('Delete <?= e((string) $f['name']) ?>? If it was ever booked or is on a published plan it is deactivated instead.')) $event.preventDefault()">
                                    <?= csrf_field() ?><?= method_field('DELETE') ?><button class="btn btn-ghost btn-sm text-red-700" aria-label="Delete <?= e((string) $f['name']) ?>"><?= icon('trash-2', 'size-4') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if ($rows === []): ?><tr><td colspan="7" class="px-5 py-6 text-center text-muted">None yet.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach ?>
