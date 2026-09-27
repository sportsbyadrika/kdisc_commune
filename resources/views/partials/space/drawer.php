<?php
/**
 * Selection drawer body — rendered twice by partials/space/explorer (desktop side panel + mobile bottom sheet),
 * both bound to the same Alpine `spaceExplorer` state.
 *
 * @var App\Core\Template $this
 * @var bool $staffMode
 * @var string $place  'panel' | 'sheet'
 */
?>
<div class="flex min-h-0 flex-1 flex-col">
<div class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain <?= $place === 'panel' ? 'p-5' : 'px-5 pt-1 pb-5' ?>">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-[11px] font-bold tracking-[0.16em] text-muted uppercase">Your selection</p>
            <h2 class="mt-1 text-xl font-extrabold" x-text="selection ? (selCategory === 'CABIN' || selCategory === 'CONF' ? selUnits[0].label : selUnits.length + ' × ' + catShort(selCategory) + ' seat' + (selUnits.length === 1 ? '' : 's')) : 'Pick your seats'"></h2>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted"><span class="inline-flex items-center gap-1.5"><?= icon('calendar-range', 'size-4 shrink-0') ?><span x-text="periodLabel"></span></span><span x-show="quote" x-cloak class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-bold text-brand-700" x-text="quote ? quote.duration.label : ''"></span></p>
        </div>
        <button type="button" x-show="selection" x-cloak @click="clearAll()" class="btn btn-ghost btn-sm -mr-2 shrink-0 text-muted"><?= icon('trash-2', 'size-4') ?>Clear</button>
    </div>

    <!-- Empty state -->
    <div x-show="!selection" class="rounded-2xl border-2 border-dashed border-line p-5">
        <div class="flex items-center gap-3">
            <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><?= icon('mouse-pointer-click', 'size-5') ?></span>
            <p class="text-sm font-semibold">Tap a <span class="text-emerald-600">green</span> seat to hold it for <span x-text="cfg.holdMinutes"></span> minutes while you decide.</p>
        </div>
        <ul class="mt-4 space-y-2 text-[13px] text-muted">
            <li class="flex gap-2"><?= icon('armchair', 'mt-0.5 size-4 shrink-0 text-emerald-600') ?>Flexi &amp; dedicated seats: pick as many as you set in “Seats needed”.</li>
            <li class="flex gap-2"><?= icon('door-open', 'mt-0.5 size-4 shrink-0 text-violet-600') ?>Cabins are booked whole — all three chairs together.</li>
            <li class="flex gap-2"><?= icon('clock', 'mt-0.5 size-4 shrink-0 text-amber-600') ?>The conference room is booked by the hour.</li>
        </ul>
    </div>

    <!-- Hold countdown -->
    <div x-show="selection" x-cloak class="flex items-center gap-3 rounded-2xl p-3 transition" :class="lowTime ? 'bg-amber-50 ring-1 ring-amber-300' : 'bg-surface'">
        <div class="relative size-11 shrink-0">
            <svg viewBox="0 0 36 36" class="size-11 -rotate-90"><circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" class="stroke-line"/><circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" pathLength="100" :stroke-dasharray="holdPct + ' 100'" :class="lowTime ? 'stroke-amber-500' : 'stroke-brand-600'" class="transition-all duration-1000 ease-linear"/></svg>
            <span class="absolute inset-0 grid place-items-center" :class="lowTime ? 'text-amber-600' : 'text-brand-600'"><?= icon('timer', 'size-4') ?></span>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold">Held for you · <span class="font-mono tabular-nums" x-text="countdown"></span></p>
            <p class="text-xs text-muted" x-text="lowTime ? 'Almost out of time — renew to keep these seats.' : 'Nobody else can pick these seats meanwhile.'"></p>
        </div>
        <button type="button" x-show="lowTime" @click="renew()" class="btn btn-sm shrink-0 bg-amber-500 text-white hover:bg-amber-600"><?= icon('refresh-cw', 'size-3.5') ?>Renew</button>
    </div>

    <!-- Selected units -->
    <div x-show="selection" x-cloak class="flex flex-wrap gap-2">
        <template x-for="u in selUnits" :key="u.id">
            <span class="inline-flex animate-pop items-center gap-1.5 rounded-full bg-brand-600 py-1 pr-1 pl-3 text-sm font-semibold text-white shadow-sm">
                <?= icon('check', 'size-3.5') ?><span x-text="u.kind === 'seat' ? u.code : u.label"></span>
                <span x-show="u.kind !== 'seat'" class="text-white/70" x-text="'· ' + u.capacity + ' seats'"></span>
                <button type="button" @click="release(u.id)" class="grid size-6 place-items-center rounded-full transition hover:bg-white/20" :aria-label="'Remove ' + u.code"><?= icon('x', 'size-3.5') ?></button>
            </span>
        </template>
    </div>

    <!-- Seats needed / auto-pick (flexi & dedicated) -->
    <div x-show="selection && multi && selUnits.length < seatsNeeded" x-cloak class="flex items-center justify-between gap-3 rounded-2xl bg-brand-50/60 px-3 py-2.5 ring-1 ring-brand-100">
        <p class="text-sm"><b x-text="selUnits.length + ' of ' + seatsNeeded"></b> seats picked</p>
        <button type="button" @click="autoPick()" class="btn btn-sm bg-white ring-1 ring-line hover:bg-surface"><?= icon('wand-sparkles', 'size-4 text-accent-500') ?>Auto-pick <span x-text="seatsNeeded - selUnits.length"></span></button>
    </div>

    <!-- Included facilities -->
    <div x-show="selection" x-cloak class="flex items-start gap-2.5 rounded-2xl bg-emerald-50/70 px-3 py-2.5 text-xs text-emerald-900 ring-1 ring-emerald-100">
        <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-emerald-500 text-white"><?= icon('check', 'size-3') ?></span>
        <p><b>Included:</b> <span x-text="included.map(f => f.emoji + ' ' + f.name).join(' · ')"></span></p>
    </div>

    <!-- Add-ons -->
    <div x-show="selection && addonList.length" x-cloak>
        <p class="mb-2 text-[11px] font-bold tracking-[0.14em] text-muted uppercase">Add facilities</p>
        <div class="grid gap-2">
            <template x-for="a in addonList" :key="a.id">
                <div class="addon-chip" :class="addonOn(a) && 'is-on'">
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left disabled:cursor-not-allowed" :aria-pressed="addonOn(a).toString()" :disabled="a.left === 0 && !addonOn(a)" @click="toggleAddon(a)">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl text-lg transition" :class="addonOn(a) ? 'bg-white shadow-sm' : 'bg-surface'" x-text="a.emoji" aria-hidden="true"></span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold" x-text="a.name"></span>
                            <span class="block text-xs text-muted"><span class="font-semibold text-ink/80" x-text="addonPrice(a)"></span><span x-show="a.left !== null" :class="a.left <= 3 ? 'text-amber-700 font-semibold' : ''" x-text="' · ' + (a.left === 0 ? 'sold out' : a.left + ' left')"></span></span>
                        </span>
                    </button>
                    <div x-show="addonOn(a) && addonMax(a) > 1" class="flex shrink-0 items-center rounded-full bg-white ring-1 ring-line">
                        <button type="button" class="grid size-7 place-items-center rounded-full hover:bg-surface" @click="stepAddon(a, -1)" aria-label="Fewer"><?= icon('minus', 'size-3.5') ?></button>
                        <span class="w-5 text-center text-sm font-bold tabular-nums" x-text="addons[a.id] || 0"></span>
                        <button type="button" class="grid size-7 place-items-center rounded-full hover:bg-surface disabled:opacity-40" :disabled="(addons[a.id] || 0) >= addonMax(a)" @click="stepAddon(a, 1)" aria-label="More"><?= icon('plus', 'size-3.5') ?></button>
                    </div>
                    <span class="grid size-6 shrink-0 place-items-center rounded-full transition" :class="addonOn(a) ? 'bg-brand-600 text-white scale-100' : 'bg-surface text-transparent ring-1 ring-line'"><?= icon('check', 'size-3.5') ?></span>
                </div>
            </template>
        </div>
    </div>

    <!-- Quote -->
    <div x-show="selection" x-cloak class="relative rounded-2xl bg-surface p-4">
        <div x-show="!quote && quoteBusy" class="space-y-2"><div class="skeleton h-4 w-2/3"></div><div class="skeleton h-4 w-1/2"></div><div class="skeleton h-6 w-full"></div></div>
        <p x-show="quoteError && !quote" class="text-sm text-red-600" x-text="quoteError"></p>
        <template x-if="quote">
            <div :class="quoteBusy && 'opacity-60'" class="transition">
                <dl class="space-y-2.5 text-sm">
                    <template x-for="(l, i) in quote.lines" :key="i">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="min-w-0"><span class="block font-semibold" x-text="(l.emoji ? l.emoji + ' ' : '') + l.label"></span><span class="block text-xs text-muted" x-text="l.detail"></span></dt>
                            <dd class="shrink-0 font-semibold tabular-nums" x-text="money(l.amount, l.amount % 1 ? 2 : 0)"></dd>
                        </div>
                    </template>
                </dl>
                <dl class="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
                    <div class="flex justify-between text-muted"><dt>Subtotal</dt><dd class="tabular-nums" x-text="money(quote.totals.taxable, quote.totals.taxable % 1 ? 2 : 0)"></dd></div>
                    <template x-if="quote.tax_mode === 'intra'">
                        <div class="space-y-1.5">
                            <div class="flex justify-between text-muted"><dt x-text="'CGST ' + (quote.lines[0].gst_rate / 2) + '%'"></dt><dd class="tabular-nums" x-text="money(quote.totals.cgst, 2)"></dd></div>
                            <div class="flex justify-between text-muted"><dt x-text="'SGST ' + (quote.lines[0].gst_rate / 2) + '%'"></dt><dd class="tabular-nums" x-text="money(quote.totals.sgst, 2)"></dd></div>
                        </div>
                    </template>
                    <template x-if="quote.tax_mode === 'inter'">
                        <div class="flex justify-between text-muted"><dt x-text="'IGST ' + quote.lines[0].gst_rate + '% (inter-state)'"></dt><dd class="tabular-nums" x-text="money(quote.totals.igst, 2)"></dd></div>
                    </template>
                </dl>
            </div>
        </template>
    </div>

    <template x-if="quote">
        <div class="flex items-start gap-2.5 rounded-xl bg-white p-3 ring-1 ring-line">
            <span class="badge shrink-0" :class="quote.payment.rule === 'advance' ? 'badge-brand' : 'badge-info'" x-text="quote.payment.rule === 'advance' ? 'Advance' : 'Security deposit'"></span>
            <p class="text-xs text-muted" x-show="quote.payment.rule === 'advance'">Pay <b class="text-ink" x-text="money(quote.payment.payable_now, quote.payment.payable_now % 1 ? 2 : 0)"></b> in advance once approved (tenure up to 6 months).</p>
            <p class="text-xs text-muted" x-show="quote.payment.rule === 'security_deposit'">Longer than 6 months: a refundable deposit of <b class="text-ink" x-text="money(quote.payment.deposit)"></b> (<span x-text="Math.round(quote.payment.deposit / quote.payment.monthly_rent)"></span> months’ rent, no GST) secures the seats; rent is billed monthly.</p>
        </div>
    </template>
<?php if ($staffMode): ?>
    <div x-show="selection" x-cloak class="space-y-3">
        <div class="rounded-2xl border p-3" :class="customer ? 'border-line' : 'border-amber-300 bg-amber-50'">
            <template x-if="customer">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white" x-text="customer.name.slice(0, 1)"></span>
                    <div class="min-w-0"><p class="truncate text-sm font-bold" x-text="customer.name"></p><p class="truncate font-mono text-xs text-muted" x-text="customer.unique_id || 'ID not issued yet'"></p></div>
                    <span class="badge ml-auto shrink-0" :class="kycTone(customer.kyc_status)" x-text="kycLabel(customer.kyc_status)"></span>
                </div>
            </template>
            <p x-show="!customer" class="flex items-center gap-2 text-sm font-semibold text-amber-800"><?= icon('user-search', 'size-4') ?>Choose the visitor at the top before booking.</p>
        </div>
        <label class="block"><span class="label">Front-desk note <span class="font-normal text-muted">(optional)</span></span>
            <textarea x-model="notes" rows="2" maxlength="1000" class="input" placeholder="e.g. walk-in, paying by UPI"></textarea></label>
    </div>
<?php endif ?>
</div>
<div x-show="selection" x-cloak class="shrink-0 border-t border-line bg-white/95 <?= $place === 'panel' ? 'rounded-b-[var(--radius-card)] p-5' : 'px-5 pt-4 pb-[max(1.25rem,env(safe-area-inset-bottom))]' ?>">
    <div x-show="quote" x-cloak class="mb-3 flex items-end justify-between gap-3">
        <div>
            <p class="text-xs font-semibold text-muted">Total incl. GST</p>
            <p class="font-display text-[26px] leading-tight font-extrabold tabular-nums" x-text="quote ? money(quote.totals.grand, quote.totals.grand % 1 ? 2 : 0) : ''"></p>
        </div>
        <span class="badge mb-1" :class="quote?.payment.rule === 'advance' ? 'badge-brand' : 'badge-info'" x-text="quote?.payment.rule === 'advance' ? 'Advance' : 'Deposit ' + money(quote?.payment.deposit || 0)"></span>
    </div>
<?php if ($staffMode): ?>
    <div class="space-y-3">
        <button type="button" @click="bookNow()" :disabled="!quote || busy" class="btn btn-brand btn-lg w-full">
            <span x-show="!busy" class="inline-flex items-center gap-2"><?= icon('calendar-check', 'size-5') ?>Create booking</span>
            <span x-show="busy">Creating…</span>
        </button>
        <p class="text-center text-xs text-muted">Created as <b>Approved</b> — log the payment on the booking page to confirm it.</p>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(url('spaces.checkout.start')) ?>" <?= $place === 'panel' ? 'x-ref="checkoutForm"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="addons" :value="addonsJson">
        <input type="hidden" name="back" :value="backUrl">
        <input type="hidden" name="renew" :value="cfg.renew ? cfg.renew.booking_no : ''">
        <button type="submit" :disabled="!quote" class="btn btn-primary btn-lg w-full">Continue to review<?= icon('arrow-right', 'size-5') ?></button>
        <p class="mt-2 flex items-center justify-center gap-1.5 text-center text-xs text-muted"><?= icon('shield-check', 'size-3.5') ?>No payment now — the Centre Manager confirms your request first.</p>
    </form>
<?php endif ?>
</div>
</div>
