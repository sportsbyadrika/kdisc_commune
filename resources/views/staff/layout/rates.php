<?php
/**
 * Rates tab: category base rates with effective-dated history (spec 5.4/5.5) + seat/zone overrides in force.
 * New rates are always new rows (RateService): the previous one is closed the day before.
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $floors
 * @var list<array<string, mixed>> $categories DesignerPresenter::categories()
 * @var array<int, list<array<string, mixed>>> $history category id => rate rows
 * @var list<array<string, mixed>> $overrides
 * @var int $open
 */
$this->layout('layouts/staff', [
    'title' => 'Rates',
    'wide' => true,
    'hideTitle' => true,
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Layout & pricing', url('staff.layout.index')], ['Rates']],
]);
$today = date('Y-m-d');
$stateTone = ['current' => 'success', 'scheduled' => 'info', 'ended' => 'neutral'];
?>
<div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-3">
    <div>
        <p class="text-[11px] font-bold tracking-[0.16em] text-muted uppercase">Layout &amp; pricing</p>
        <h1 class="text-2xl font-extrabold">Base rates</h1>
    </div>
    <?= $this->partial('partials/layout/nav', ['active' => 'rates']) ?>
</div>

<div class="mb-6 grid grid-cols-1 gap-3 rounded-2xl bg-brand-50/70 p-4 text-sm text-brand-900 ring-1 ring-brand-100 sm:grid-cols-3">
    <p class="flex gap-2"><?= icon('layers', 'size-4 mt-0.5 shrink-0') ?><span><b>Seat → zone → base rate.</b> A seat uses its own override, else its zone’s rate, else the base rate of its space type (set here).</span></p>
    <p class="flex gap-2"><?= icon('calendar-range', 'size-4 mt-0.5 shrink-0') ?><span><b>Effective-dated.</b> A new rate starts on its date; the previous one is closed the day before. Quotes use the rate on the booking’s start date.</span></p>
    <p class="flex gap-2"><?= icon('lock', 'size-4 mt-0.5 shrink-0') ?><span><b>Never rewritten.</b> A rate that priced a booking is locked; bookings keep the price they were created with.</span></p>
</div>

<div class="space-y-6">
    <?php foreach ($categories as $c):
        $rows = $history[$c['id']] ?? [];
        $current = [];
        foreach ($rows as $r) {
            if ($r['state'] === 'current') {
                $current[(string) $r['unit']] ??= $r;
            }
        }
        $isOpen = $open === $c['id'];
    ?>
        <section id="cat-<?= (int) $c['id'] ?>" class="card overflow-hidden <?= $isOpen ? 'ring-2 ring-brand-600/30' : '' ?>" data-test="rates-<?= e($c['code']) ?>">
            <header class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-line px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-xl text-white" style="background: <?= e($c['colour']) ?>"><?= icon($c['icon'], 'size-5') ?></span>
                    <div><h2 class="text-lg font-extrabold"><?= e($c['name']) ?></h2><p class="text-xs text-muted">Billed per <?= e(implode(' / ', $c['units'])) ?><?= $c['whole_unit'] ? ' · whole unit' : '' ?><?= $c['hourly'] ? ' · hourly' : '' ?></p></div>
                </div>
                <div class="flex flex-wrap gap-4 sm:ml-auto">
                    <?php foreach ($c['units'] as $u): $r = $current[$u] ?? null; ?>
                        <div class="text-right">
                            <p class="font-display text-2xl font-extrabold tabular-nums"><?= $r !== null ? e(money((float) $r['amount'])) : '<span class="text-red-600">—</span>' ?><span class="text-sm font-semibold text-muted">/<?= e($u) ?></span></p>
                            <p class="text-xs text-muted"><?= $r !== null ? 'GST ' . e((string) (float) $r['gst_rate']) . '% · since ' . e(format_date((string) $r['effective_from'])) : 'No rate — publishing is blocked' ?></p>
                        </div>
                    <?php endforeach ?>
                </div>
            </header>
            <div class="grid grid-cols-1 gap-0 lg:grid-cols-[340px_minmax(0,1fr)]">
                <form method="post" action="<?= e(url('staff.layout.rates.store')) ?>" class="space-y-3 border-b border-line bg-surface/50 p-5 lg:border-r lg:border-b-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                    <p class="text-sm font-bold">Set a new base rate</p>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="dz-field"><span>Unit</span><select name="unit" class="dz-input"><?php foreach ($c['units'] as $u): ?><option value="<?= e($u) ?>" <?= $u === (string) old('unit') ? 'selected' : '' ?>>per <?= e($u) ?></option><?php endforeach ?></select></label>
                        <label class="dz-field"><span>Amount ₹</span><input name="amount" type="number" min="1" step="1" required class="dz-input tabular-nums" value="<?= $isOpen ? e((string) old('amount')) : '' ?>"></label>
                        <label class="dz-field"><span>GST %</span><input name="gst_rate" type="number" min="0" max="28" step="0.01" required class="dz-input" value="<?= e((string) setting('gst_rate', 18)) ?>"></label>
                        <label class="dz-field"><span>Effective from</span><input name="effective_from" type="date" min="<?= e($today) ?>" required class="dz-input" value="<?= e($isOpen && old('effective_from') ? (string) old('effective_from') : $today) ?>"></label>
                    </div>
                    <label class="dz-field"><span>Note (optional)</span><input name="note" maxlength="255" class="dz-input" placeholder="e.g. FY 2027 revision"></label>
                    <?php if ($isOpen && errors('amount')): ?><p class="text-xs font-semibold text-red-600"><?= e((string) errors('amount')) ?></p><?php endif ?>
                    <?php if ($isOpen && errors('effective_from')): ?><p class="text-xs font-semibold text-red-600"><?= e((string) errors('effective_from')) ?></p><?php endif ?>
                    <button class="btn btn-brand btn-sm w-full"><?= icon('check', 'size-4') ?>Save rate</button>
                </form>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-surface text-left text-[11px] font-bold tracking-wide text-muted uppercase">
                            <tr><th class="px-4 py-2.5">Unit</th><th class="px-4 py-2.5 text-right">Amount</th><th class="px-4 py-2.5">GST</th><th class="px-4 py-2.5">Effective</th><th class="px-4 py-2.5">State</th><th class="px-4 py-2.5">Set by</th></tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <?php foreach ($rows as $r): ?>
                                <tr class="<?= $r['state'] === 'ended' ? 'text-ink/50' : '' ?>">
                                    <td class="px-4 py-2.5 font-semibold">per <?= e((string) $r['unit']) ?></td>
                                    <td class="px-4 py-2.5 text-right font-bold tabular-nums"><?= e(money((float) $r['amount'])) ?></td>
                                    <td class="px-4 py-2.5"><?= e((string) (float) $r['gst_rate']) ?>%</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap"><?= e(format_date((string) $r['effective_from'])) ?> → <?= $r['effective_to'] !== null ? e(format_date((string) $r['effective_to'])) : '<span class="text-muted">open</span>' ?></td>
                                    <td class="px-4 py-2.5"><span class="flex flex-wrap gap-1"><?= $this->component('badge', ['label' => ucfirst((string) $r['state']), 'tone' => $stateTone[$r['state']]]) ?><?php if ($r['locked']): ?><?= $this->component('badge', ['label' => 'Priced bookings', 'tone' => 'warning', 'icon' => 'lock']) ?><?php endif ?></span></td>
                                    <td class="px-4 py-2.5 text-xs text-muted"><?= e((string) ($r['created_by_name'] ?? 'Seed data')) ?><br><?= e(format_date((string) $r['created_at'], 'd M Y, h:i A')) ?><?php if (!empty($r['notes'])): ?><br><i><?= e((string) $r['notes']) ?></i><?php endif ?></td>
                                </tr>
                            <?php endforeach ?>
                            <?php if ($rows === []): ?><tr><td colspan="6" class="px-4 py-6 text-center text-muted">No rates yet.</td></tr><?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endforeach ?>

    <section class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
            <div><h2 class="text-lg font-extrabold">Seat &amp; zone overrides in force</h2><p class="text-xs text-muted">Set them in the floor designer (select seats or a zone → Pricing).</p></div>
            <a href="<?= e(url('staff.layout.index')) ?>" class="btn btn-outline btn-sm"><?= icon('pen-tool', 'size-4') ?>Open designer</a>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface text-left text-[11px] font-bold tracking-wide text-muted uppercase">
                    <tr><th class="px-4 py-2.5">Applies to</th><th class="px-4 py-2.5">Unit</th><th class="px-4 py-2.5 text-right">Amount</th><th class="px-4 py-2.5">GST</th><th class="px-4 py-2.5">Effective</th><th class="px-4 py-2.5">Set by</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <?php foreach ($overrides as $r): ?>
                        <tr>
                            <td class="px-4 py-2.5"><?= $this->component('badge', ['label' => ucfirst((string) $r['scope']), 'tone' => $r['scope'] === 'seat' ? 'accent' : 'info']) ?> <b class="ml-1 font-mono text-xs"><?= e((string) ($r['target'] ?? '#' . $r['scope_id'])) ?></b></td>
                            <td class="px-4 py-2.5">per <?= e((string) $r['unit']) ?></td>
                            <td class="px-4 py-2.5 text-right font-bold tabular-nums"><?= e(money((float) $r['amount'])) ?></td>
                            <td class="px-4 py-2.5"><?= e((string) (float) $r['gst_rate']) ?>%</td>
                            <td class="px-4 py-2.5 whitespace-nowrap"><?= e(format_date((string) $r['effective_from'])) ?> → <?= $r['effective_to'] !== null ? e(format_date((string) $r['effective_to'])) : '<span class="text-muted">open</span>' ?></td>
                            <td class="px-4 py-2.5 text-xs text-muted"><?= e((string) ($r['created_by_name'] ?? '—')) ?><?php if (!empty($r['notes'])): ?> · <i><?= e((string) $r['notes']) ?></i><?php endif ?></td>
                        </tr>
                    <?php endforeach ?>
                    <?php if ($overrides === []): ?><tr><td colspan="6" class="px-4 py-6 text-center text-muted">No seat or zone overrides — every seat uses its base rate.</td></tr><?php endif ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
