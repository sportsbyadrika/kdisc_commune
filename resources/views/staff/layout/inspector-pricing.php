<?php
/**
 * Designer inspector — Pricing tab. Shows the effective rate of the selection (seat → zone → category, spec 5.5)
 * and where it comes from; sets / clears seat or zone overrides (bulk for multi-selection) via
 * POST /staff/layout/api/versions/{v}/rates. Rates are effective-dated and live on their date — independent of
 * publishing the layout.
 *
 * @var App\Core\Template $this
 * @var bool $canPrice
 */
?>
<!-- nothing selected: base rates -->
<template x-if="!priceTarget()">
    <div class="space-y-4">
        <p class="text-sm text-muted">Select a seat, a cabin, several seats or a zone to see and change its price. Base rates per space type:</p>
        <template x-for="c in cfg.categories" :key="c.id">
            <div class="flex items-center justify-between gap-2 rounded-xl border border-line px-3 py-2.5">
                <span class="flex items-center gap-2 text-sm font-bold"><span class="size-2.5 rounded-full" :style="`background:${c.colour}`"></span><span x-text="c.short"></span></span>
                <span class="text-right text-sm tabular-nums">
                    <template x-for="u in c.units" :key="u"><span class="block"><b x-text="catRates(c.id)[u] ? money(catRates(c.id)[u].amount) : '—'"></b><span class="text-muted" x-text="' /' + u"></span></span></template>
                </span>
            </div>
        </template>
        <a :href="cfg.urls.rates" class="btn btn-outline btn-sm w-full"><?= icon('indian-rupee', 'size-4') ?>Edit base rates &amp; history</a>
    </div>
</template>

<template x-if="priceTarget()">
    <div class="space-y-5">
        <!-- single unit: resolved rate + source -->
        <template x-if="oneType === 's' && one && !one.parent_id">
            <div>
                <p class="dz-h !p-0 !pb-2">Effective price today</p>
                <div class="space-y-1.5" data-test="effective-price">
                    <template x-for="u in (catOfSeat(one)?.units || [])" :key="u">
                        <div class="flex items-center justify-between gap-2 rounded-xl border border-line px-3 py-2.5">
                            <div class="min-w-0">
                                <p class="text-lg font-extrabold whitespace-nowrap tabular-nums"><span x-text="resolved(one)?.[u] ? money(resolved(one)[u].amount) : '—'"></span> <span class="text-xs font-semibold text-muted" x-text="unitLabel(u)"></span></p>
                                <p class="text-xs text-muted" x-show="resolved(one)?.[u]" x-text="'GST ' + resolved(one)?.[u]?.gst_rate + '%'"></p>
                            </div>
                            <span class="badge max-w-[9rem] shrink-0 truncate whitespace-nowrap" :class="{ seat: 'badge-accent', zone: 'badge-info', category: 'badge-neutral' }[resolved(one)?.[u]?.source] || 'badge-danger'" x-text="resolved(one)?.[u] ? rateSource(resolved(one)[u].source, one) : 'No rate'"></span>
                        </div>
                    </template>
                </div>
                <p x-show="typeof one.id !== 'number'" class="mt-2 text-xs text-muted">New seat — it is saved automatically before its price is set.</p>
                <template x-if="Object.keys(overrides(one)).length">
                    <div class="mt-3 rounded-xl bg-accent-50 p-3 text-xs text-accent-700">
                        <template x-for="(o, u) in overrides(one)" :key="u">
                            <p class="flex items-center justify-between gap-2"><span>Override <b x-text="money(o.amount)"></b> <span x-text="unitLabel(u)"></span> since <span x-text="fmtDate(o.from)"></span></span>
                                <?php if ($canPrice): ?><button type="button" class="font-bold underline" @click="clearPrice(u)" :disabled="priceBusy">Clear</button><?php endif ?></p>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        <!-- several units -->
        <template x-if="priceTarget()?.target === 'seat' && selUnits.length > 1">
            <div>
                <p class="dz-h !p-0 !pb-2">Current prices of <span x-text="selUnits.length"></span> units</p>
                <template x-for="row in bulkPrices()" :key="row.unit">
                    <p class="flex justify-between rounded-xl border border-line px-3 py-2 text-sm"><span class="text-muted" x-text="unitLabel(row.unit)"></span><b class="tabular-nums" x-text="row.values.length === 1 ? money(row.values[0]) : money(row.values[0]) + ' – ' + money(row.values[row.values.length - 1])"></b></p>
                </template>
                <p x-show="!priceUnits.length" class="rounded-xl bg-amber-50 p-3 text-xs text-amber-800">The selection mixes space types (or has seats outside a zone) — select units of one space type to bulk-edit.</p>
            </div>
        </template>

        <!-- zone -->
        <template x-if="priceTarget()?.target === 'zone'">
            <div class="space-y-2">
                <p class="text-sm text-muted">A zone rate applies to every seat in <b class="text-ink" x-text="one?.name"></b> that has no seat override.</p>
                <template x-for="u in (cat(one?.category_id)?.units || [])" :key="u">
                    <div class="flex items-center justify-between rounded-xl border border-line px-3 py-2.5 text-sm">
                        <span><b class="tabular-nums" x-text="zoneRates(one)[u] ? money(zoneRates(one)[u].amount) : (catRates(one.category_id)[u] ? money(catRates(one.category_id)[u].amount) : '—')"></b> <span class="text-muted" x-text="unitLabel(u)"></span></span>
                        <span class="badge" :class="zoneRates(one)[u] ? 'badge-info' : 'badge-neutral'" x-text="zoneRates(one)[u] ? 'Zone rate since ' + fmtDate(zoneRates(one)[u].from) : 'Base rate'"></span>
                    </div>
                </template>
                <p x-show="!one?.category_id" class="rounded-xl bg-amber-50 p-3 text-xs text-amber-800">Give the zone a space type first.</p>
            </div>
        </template>

        <?php if ($canPrice): ?>
        <form x-show="priceUnits.length" class="space-y-3 rounded-2xl border border-line bg-surface/60 p-3" @submit.prevent="submitPrice()" data-test="price-form">
            <p class="text-sm font-bold" x-text="priceTarget()?.target === 'zone' ? 'Set zone rate' : (selUnits.length > 1 ? 'Set price for ' + selUnits.length + ' units' : 'Set seat override')"></p>
            <div class="grid grid-cols-2 gap-2">
                <label class="dz-field"><span>Unit</span><select class="dz-input" x-model="priceForm.unit"><template x-for="u in priceUnits" :key="u"><option :value="u" x-text="{ day: 'Day', month: 'Month', hour: 'Hour' }[u]"></option></template></select></label>
                <label class="dz-field"><span>Amount ₹</span><input type="number" min="1" step="1" required class="dz-input tabular-nums" x-model="priceForm.amount" data-test="price-amount"></label>
                <label class="dz-field"><span>GST %</span><input type="number" min="0" max="28" step="0.01" required class="dz-input" x-model="priceForm.gst_rate"></label>
                <label class="dz-field"><span>Effective from</span><input type="date" required class="dz-input" :min="cfg.today" x-model="priceForm.effective_from"></label>
            </div>
            <label class="dz-field"><span>Note (optional)</span><input class="dz-input" maxlength="255" x-model="priceForm.note" placeholder="e.g. Premium window seat"></label>
            <p class="text-xs text-muted">The current rate is closed the day before. Rates that already priced bookings are never edited — bookings keep their price.</p>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-brand btn-sm flex-1" :disabled="priceBusy || !priceForm.amount" data-test="price-submit"><span x-show="priceBusy" class="size-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>Apply</button>
                <button type="button" class="btn btn-outline btn-sm" @click="clearPrice(null)" :disabled="priceBusy" title="Remove overrides from this date">Clear override</button>
            </div>
        </form>
        <?php else: ?>
        <p class="rounded-xl bg-surface p-3 text-xs text-muted">Your role can view prices but not change them.</p>
        <?php endif ?>
    </div>
</template>
