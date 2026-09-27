<?php
/**
 * Space Explorer — Level 2 floor map with selection drawer (spec 5.1–5.3). Shared by the public site
 * (site/explore/floor) and receptionist mode (staff/explorer/index). Behaviour: resources/js/explorer.js.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $config  ExplorerPresenter::floorConfig()
 * @var bool $staffMode
 */
$staffMode ??= false;
$floors = $config['initial']['floors'];
$currentFloor = $config['initial']['floor'];
?>
<div x-data="spaceExplorer" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     @keydown.escape.window="slot.open = false; signIn = false; switchTo = null; sheet = false; hideTip()"
     class="<?= $staffMode ? '' : 'pb-28 lg:pb-0' ?>">
    <?= $this->partial('partials/space/sprite', ['extra' => array_column($config['initial']['facilities'] ?? [], 'icon')]) ?>

<?php if (!$staffMode): ?>
    <div x-show="cfg.renew" x-cloak class="mb-4 flex items-start gap-3 rounded-2xl bg-brand-50 p-4 text-sm text-brand-900 ring-1 ring-brand-200">
        <?= icon('refresh-cw', 'size-5 shrink-0 text-brand-600') ?>
        <p>Renewing <b class="font-mono" x-text="cfg.renew?.booking_no"></b> — your seats <b x-text="(cfg.renew?.codes || []).join(', ')"></b> are preselected for <span x-text="fmtDate(cfg.renew?.from) + ' → ' + fmtDate(cfg.renew?.to)"></span> at today’s rates. Change the dates or seats if you like, then continue.</p>
    </div>
<?php endif ?>
<?php if ($staffMode): ?>
    <!-- Reception: visitor picker + override -->
    <div class="card mb-4 flex flex-col gap-3 p-3 sm:p-4 lg:flex-row lg:items-center">
        <div class="relative min-w-0 flex-1" @click.outside="custOpen = false">
            <label class="sr-only" for="sx-customer">Find visitor</label>
            <div class="flex items-center gap-2 rounded-2xl bg-surface px-3 ring-1 ring-line focus-within:bg-white focus-within:ring-2 focus-within:ring-brand-600/30">
                <?= icon('user-search', 'size-5 shrink-0 text-muted') ?>
                <input id="sx-customer" x-ref="custInput" type="search" autocomplete="off" x-model="custQuery" @input="searchCustomers()" @focus="custOpen = custResults.length > 0"
                       class="w-full border-0 bg-transparent py-3 text-sm font-medium focus:ring-0 focus:outline-none" placeholder="Book for… search Unique ID, name or mobile">
                <span x-show="custBusy" class="size-4 shrink-0 animate-spin rounded-full border-2 border-brand-600 border-t-transparent"></span>
            </div>
            <div x-show="custOpen && custQuery.length >= 2" x-cloak x-transition.opacity class="absolute inset-x-0 top-full z-40 mt-2 overflow-hidden rounded-2xl border border-line bg-white shadow-[var(--shadow-card-hover)]">
                <template x-for="c in custResults" :key="c.id">
                    <button type="button" @click="pickCustomer(c)" class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-surface">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-bold text-brand-700" x-text="c.name.slice(0, 1)"></span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold" x-text="c.name"></span><span class="block truncate text-xs text-muted" x-text="[c.unique_id, c.mobile].filter(Boolean).join(' · ')"></span></span>
                        <span class="badge shrink-0" :class="kycTone(c.kyc_status)" x-text="kycLabel(c.kyc_status)"></span>
                    </button>
                </template>
                <div x-show="!custResults.length" class="px-4 py-4 text-sm text-muted">No visitor found.<?php if (!empty($config['registerVisitorUrl'])): ?> <a class="font-semibold text-brand-700 underline" href="<?= e((string) $config['registerVisitorUrl']) ?>">Register a new visitor</a><?php endif ?></div>
            </div>
        </div>
        <template x-if="customer">
            <div class="flex items-center gap-3 rounded-2xl bg-brand-50 px-3 py-2 ring-1 ring-brand-200">
                <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white" x-text="customer.name.slice(0, 1)"></span>
                <div class="min-w-0"><p class="max-w-48 truncate text-sm font-bold" x-text="customer.name"></p><p class="font-mono text-xs text-brand-800/70" x-text="customer.unique_id || 'No ID yet'"></p></div>
                <span class="badge" :class="kycTone(customer.kyc_status)" x-text="kycLabel(customer.kyc_status)"></span>
                <button type="button" @click="customer = null; syncUrl(); $refs.custInput.focus()" class="btn btn-ghost btn-icon size-8" aria-label="Change visitor"><?= icon('x', 'size-4') ?></button>
            </div>
        </template>
        <?php if (!empty($config['canOverride'])): ?>
            <div class="flex items-center gap-3 lg:border-l lg:border-line lg:pl-4">
                <button type="button" role="switch" id="override-switch" aria-labelledby="override-label" :aria-checked="override.toString()" @click="override = !override; paint()" class="relative h-6 w-11 shrink-0 rounded-full transition" :class="override ? 'bg-accent-500' : 'bg-line'">
                    <span class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition" :class="override && 'translate-x-5'"></span>
                </button>
                <span id="override-label" class="text-sm font-semibold whitespace-nowrap">Override blocked / held seats</span>
                <input x-show="override" x-cloak x-ref="overrideReason" x-model="overrideReason" maxlength="500" class="input !py-2 lg:w-56" placeholder="Reason (audit-logged)" aria-label="Override reason (audit-logged)">
            </div>
        <?php endif ?>
    </div>
<?php endif ?>

    <!-- Toolbar: floors, dates, category filter, view -->
    <div class="<?= $staffMode ? 'card mb-4 p-3' : 'lg:sticky lg:top-[72px] z-30 -mx-4 mb-5 border-b border-line/70 bg-white/90 px-4 py-3 backdrop-blur-xl sm:mx-0 sm:rounded-2xl sm:border sm:shadow-[var(--shadow-card)]' ?>">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2.5">
            <div class="flex items-center gap-2">
                <?php if (!$staffMode): ?>
                    <a :href="buildingHref()" class="btn btn-ghost btn-icon size-10 shrink-0" aria-label="Back to the building"><?= icon('building-2', 'size-5') ?></a>
                <?php endif ?>
                <nav class="flex rounded-full bg-surface-2 p-1" aria-label="Floors">
                    <?php foreach ($floors as $f): $on = $f['slug'] === $currentFloor['slug']; ?>
                        <a :href="floorHref('<?= e($f['slug']) ?>')" class="<?= $on ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' ?> inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-bold whitespace-nowrap transition" <?= $on ? 'aria-current="page"' : '' ?>>
                            <?= icon('layers', 'size-4') ?><?= e(str_replace(' Floor', '', (string) $f['name'])) ?>
                        </a>
                    <?php endforeach ?>
                </nav>
            </div>
            <div class="order-3 flex items-center gap-2 rounded-2xl bg-surface px-3 py-1.5 ring-1 ring-line sm:order-none">
                <?= icon('calendar-range', 'size-5 shrink-0 text-brand-600') ?>
                <label class="sr-only" for="sx-from">From</label>
                <input id="sx-from" type="date" x-model="from" :min="cfg.today" @change="datesChanged()" class="w-[8.6rem] border-0 bg-transparent p-1 text-sm font-semibold focus:ring-0">
                <span class="text-muted">→</span>
                <label class="sr-only" for="sx-to">To</label>
                <input id="sx-to" type="date" x-model="to" :min="from" @change="datesChanged()" class="w-[8.6rem] border-0 bg-transparent p-1 text-sm font-semibold focus:ring-0">
            </div>
            <div class="order-4 -mx-1 flex w-full min-w-0 items-center gap-1.5 overflow-x-auto px-1 pb-0.5 sm:order-none sm:w-auto sm:flex-1 sm:flex-wrap sm:overflow-visible">
                <button type="button" class="chip !px-3.5 !py-1.5 shrink-0" :class="!filter && 'chip-active'" @click="filter = ''">All</button>
                <template x-for="c in cfg.categories" :key="c.code">
                    <button type="button" class="chip !px-3.5 !py-1.5 shrink-0" :class="filter === c.code && 'chip-active'" @click="filter = filter === c.code ? '' : c.code; if (filter) focusCategory(filter)" x-text="c.short"></button>
                </template>
            </div>
            <div class="order-2 ml-auto flex items-center gap-2 sm:order-none">
                <div class="flex rounded-full bg-surface p-1 ring-1 ring-line" role="group" aria-label="View">
                    <button type="button" class="grid size-8 place-items-center rounded-full transition" :class="view === 'map' ? 'bg-white shadow-sm text-ink' : 'text-muted'" @click="view = 'map'" aria-label="Map view" :aria-pressed="(view === 'map').toString()"><?= icon('map', 'size-4') ?></button>
                    <button type="button" class="grid size-8 place-items-center rounded-full transition" :class="view === 'list' ? 'bg-white shadow-sm text-ink' : 'text-muted'" @click="view = 'list'" aria-label="List view" :aria-pressed="(view === 'list').toString()"><?= icon('list', 'size-4') ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        <section class="min-w-0">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-2xl font-extrabold sm:text-[28px]"><?= e((string) $currentFloor['name']) ?></h2>
                    <p class="text-sm text-muted"><b class="text-emerald-700" x-text="freeCount"></b> of <span x-text="unitCount"></span> <span x-text="filter ? catShort(filter).toLowerCase() + ' ' : ''"></span>units free · <span x-text="periodLabel"></span></p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                <div x-show="!selCategory || multi" class="flex items-center gap-1 rounded-full bg-white p-1 pl-3 shadow-xs ring-1 ring-line">
                    <span class="mr-1 text-xs font-bold tracking-wide text-muted uppercase">Seats needed</span>
                    <button type="button" class="grid size-8 place-items-center rounded-full hover:bg-surface disabled:opacity-40" :disabled="seatsNeeded <= Math.max(1, selUnits.length)" @click="seatsNeeded--; syncUrl()" aria-label="Fewer seats"><?= icon('minus', 'size-4') ?></button>
                    <span class="w-6 text-center text-sm font-extrabold tabular-nums" x-text="seatsNeeded" aria-live="polite"></span>
                    <button type="button" class="grid size-8 place-items-center rounded-full hover:bg-surface disabled:opacity-40" :disabled="seatsNeeded >= cfg.maxSeats" @click="seatsNeeded++; syncUrl()" aria-label="More seats"><?= icon('plus', 'size-4') ?></button>
                </div>
                <button type="button" x-show="(!selCategory || multi) && seatsNeeded > selUnits.length && (filter === 'DEDICATED' || filter === 'FLEXI' || (selCategory && multi))" x-cloak @click="cfg.loggedIn ? autoPick() : (signIn = true)" class="btn btn-outline btn-sm">
                    <?= icon('wand-sparkles', 'size-4 text-accent-500') ?>Auto-pick <span x-text="seatsNeeded - selUnits.length"></span> adjacent
                </button>
                </div>
            </div>

            <!-- Map -->
            <div x-show="view === 'map'" class="map-viewport" x-ref="viewport">
                <div class="map-stage" x-ref="stage">
                    <img src="<?= e((string) $currentFloor['image']) ?>" alt="<?= e((string) $currentFloor['name']) ?> plan" draggable="false">
                    <svg x-ref="svg" xmlns="http://www.w3.org/2000/svg" role="group" aria-label="Seats on <?= e((string) $currentFloor['name']) ?>"></svg>
                </div>
                <div class="absolute inset-0 grid place-items-center bg-white/50 backdrop-blur-[2px] transition-opacity" :class="loading ? 'opacity-100' : 'pointer-events-none opacity-0'"><span class="size-8 animate-spin rounded-full border-4 border-brand-600 border-t-transparent"></span></div>
                <div class="absolute top-3 right-3 flex flex-col gap-2">
                    <button type="button" class="map-ctrl" @click="zoomIn()" aria-label="Zoom in"><?= icon('zoom-in', 'size-5') ?></button>
                    <button type="button" class="map-ctrl" @click="zoomOut()" aria-label="Zoom out"><?= icon('zoom-out', 'size-5') ?></button>
                    <button type="button" class="map-ctrl" @click="resetZoom()" aria-label="Reset view"><?= icon('locate-fixed', 'size-5') ?></button>
                </div>
                <div x-show="mini.show" x-cloak @click="miniJump($event)" class="absolute right-3 bottom-3 w-28 cursor-pointer overflow-hidden rounded-lg bg-white/90 shadow-lg ring-1 ring-line sm:w-36" aria-hidden="true">
                    <img src="<?= e((string) $currentFloor['image']) ?>" alt="" class="block w-full opacity-80">
                    <span class="absolute rounded-sm border-2 border-accent-500 bg-accent-500/10" :style="`left:${mini.x}%;top:${mini.y}%;width:${mini.w}%;height:${mini.h}%`"></span>
                </div>
                <p x-data="{ on: true }" x-init="setTimeout(() => on = false, 6000)" x-show="on" x-transition.opacity.duration.500ms class="glass pointer-events-none absolute bottom-3 left-3 hidden items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold text-ink/80 sm:inline-flex"><?= icon('move', 'size-3.5') ?>Drag to pan · Ctrl + scroll to zoom</p>
            </div>

            <!-- Legend -->
            <div x-show="view === 'map'" class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs font-semibold text-ink/70">
                <?php foreach ([
                    ['bg-seat-free', 'armchair', 'Available'], ['bg-seat-selected', 'check', 'Selected'], ['bg-seat-hold', 'hourglass', 'On hold'],
                    ['bg-seat-taken/50 bg-[repeating-linear-gradient(45deg,transparent_0_3px,rgb(255_255_255/.6)_3px_6px)]', 'user', 'Booked'], ['bg-seat-blocked', 'lock', 'Blocked'],
                ] as [$cls, $ico, $label]): ?>
                    <span class="inline-flex items-center gap-1.5"><span class="grid size-5 place-items-center rounded-md text-white <?= $cls ?>"><?= icon($ico, 'size-3') ?></span><?= e($label) ?></span>
                <?php endforeach ?>
                <span class="inline-flex items-center gap-1.5"><span class="grid size-5 place-items-center rounded-full bg-white text-emerald-700 ring-2 ring-emerald-500"><?= icon('wifi', 'size-3') ?></span>Included</span>
                <span class="inline-flex items-center gap-1.5"><span class="grid size-5 place-items-center rounded-full bg-brand-50 text-brand-700 ring-2 ring-brand-500"><?= icon('lock', 'size-3') ?></span>Add-on</span>
                <span class="inline-flex items-center gap-1.5"><span class="grid size-5 place-items-center rounded-full bg-brand-900 text-white"><?= icon('door-open', 'size-3') ?></span>Landmark</span>
            </div>

            <!-- List view (accessible fallback) -->
            <div x-show="view === 'list'" x-cloak class="space-y-6">
                <template x-for="z in listZones" :key="z.id">
                    <section :class="z.dim && 'opacity-40'">
                        <div class="mb-2 flex items-center gap-2">
                            <span class="size-3 rounded-full" :style="`background:${z.colour}`"></span>
                            <h3 class="font-bold" x-text="z.name"></h3>
                            <span class="text-sm text-muted" x-text="'· ' + catShort(z.category) + ' · ' + z.items.filter(u => u.status === 'available').length + ' free'"></span>
                        </div>
                        <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                            <template x-for="u in z.items" :key="u.id">
                                <li>
                                    <button type="button" @click="activate(u.id)" :aria-pressed="(u.status === 'mine').toString()"
                                            class="flex w-full items-center gap-2.5 rounded-xl border bg-white p-2.5 text-left transition hover:border-brand-400"
                                            :class="{ 'border-brand-600 ring-2 ring-brand-600/20': u.status === 'mine', 'border-line': u.status !== 'mine', 'opacity-60': ['occupied', 'blocked', 'held'].includes(u.status) }">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-lg text-white"
                                              :class="{ 'bg-seat-free': u.status === 'available', 'bg-seat-selected': u.status === 'mine', 'bg-seat-hold': u.status === 'held', 'bg-seat-taken/60': u.status === 'occupied', 'bg-seat-blocked': u.status === 'blocked' }">
                                            <svg class="size-4"><use :href="'#i-' + ({ available: 'armchair', mine: 'check', held: 'hourglass', occupied: 'user', blocked: 'lock' })[u.status]"></use></svg>
                                        </span>
                                        <span class="min-w-0"><span class="block truncate text-sm font-bold" x-text="u.kind === 'seat' ? u.code : u.label"></span><span class="block truncate text-xs text-muted" x-text="statusLabel(u.status) + ' · ' + priceText(u.rates)"></span></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </section>
                </template>
            </div>
        </section>

        <!-- Desktop drawer -->
        <aside class="hidden lg:block">
            <div class="card sticky flex flex-col <?= $staffMode ? 'top-24 max-h-[calc(100vh-7rem)]' : 'top-[168px] max-h-[max(560px,calc(100vh-280px))]' ?>">
                <?= $this->partial('partials/space/drawer', ['staffMode' => $staffMode, 'place' => 'panel']) ?>
            </div>
        </aside>
    </div>

    <!-- Mobile bottom sheet -->
    <div class="lg:hidden" x-show="selection" x-cloak>
        <div x-show="sheet" x-transition.opacity class="fixed inset-0 z-40 bg-brand-950/50 backdrop-blur-[2px]" @click="sheet = false"></div>
        <div class="fixed inset-x-0 bottom-0 z-50 rounded-t-3xl bg-white shadow-[0_-18px_40px_-12px_rgb(7_15_38/.35)] transition-transform duration-300 ease-[var(--ease-spring)]" x-ref="sheetBar">
            <button type="button" class="flex w-full flex-col items-center pt-2" @click="sheet = !sheet" :aria-expanded="sheet.toString()" aria-label="Show selection details">
                <span class="h-1.5 w-10 rounded-full bg-line"></span>
            </button>
            <div x-show="!sheet" class="flex items-center gap-3 px-4 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <button type="button" class="min-w-0 flex-1 text-left" @click="sheet = true">
                    <p class="truncate text-sm font-bold"><span x-text="selUnits.map(u => u.kind === 'seat' ? u.code : u.label).join(', ')"></span></p>
                    <p class="flex items-center gap-2 text-xs text-muted"><span class="font-mono tabular-nums" :class="lowTime && 'font-bold text-amber-600'" x-text="'⏱ ' + countdown"></span><span x-show="quote" class="font-bold text-ink" x-text="quote ? money(quote.totals.grand) : ''"></span><span class="font-semibold text-brand-700">Details ›</span></p>
                </button>
                <?php if ($staffMode): ?>
                    <button type="button" class="btn btn-brand shrink-0" @click="sheet = true">Review<?= icon('chevron-up', 'size-4') ?></button>
                <?php else: ?>
                    <button type="button" class="btn btn-primary shrink-0" :disabled="!quote" @click="checkout()">Continue<?= icon('arrow-right', 'size-4') ?></button>
                <?php endif ?>
            </div>
            <div x-show="sheet" class="flex max-h-[82vh] flex-col">
                <?= $this->partial('partials/space/drawer', ['staffMode' => $staffMode, 'place' => 'sheet']) ?>
            </div>
        </div>
    </div>

    <!-- Seat / facility tooltip -->
    <div x-cloak class="pointer-events-none fixed z-[70] w-72 transition-opacity duration-150" :class="tip.show ? 'opacity-100 visible' : 'opacity-0 invisible'" :style="`left:${tip.x}px;top:${tip.y}px;transform:translate(-50%, ${tip.below ? '0' : '-100%'})`" role="tooltip">
        <div class="glass rounded-2xl p-4">
            <template x-if="tipSeat">
                <div>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-display text-lg font-extrabold" x-text="tipSeat.kind === 'seat' ? tipSeat.code : tipSeat.label"></p>
                            <p class="text-xs font-semibold text-muted" x-text="catMeta(tipSeat.category)?.label + (tipSeat.kind !== 'seat' ? ' · ' + tipSeat.capacity + ' seats' : '')"></p>
                        </div>
                        <span class="badge shrink-0" :class="{ available: 'badge-success', mine: 'badge-brand', held: 'badge-warning', occupied: 'badge-danger', blocked: 'badge-neutral' }[tipStatus()]" x-text="statusLabel(tipStatus())"></span>
                    </div>
                    <p class="mt-2 text-sm font-bold text-ink" x-text="priceText(tipSeat.rates)"></p>
                    <p x-show="nearHint(tipSeat)" class="mt-1 flex items-center gap-1 text-xs text-muted"><?= icon('map-pin', 'size-3.5') ?><span x-text="nearHint(tipSeat)"></span></p>
                    <div class="mt-2.5 flex flex-wrap gap-1">
                        <template x-for="f in (tipSeat.included || []).slice(0, 6)" :key="f.code"><span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700" x-text="f.emoji + ' ' + f.name"></span></template>
                    </div>
                    <template x-if="tipSeat.occupant">
                        <div class="mt-3 rounded-xl bg-red-50 p-2.5 text-xs ring-1 ring-red-100">
                            <p class="font-bold text-red-800" x-text="tipSeat.occupant.name"></p>
                            <p class="font-mono text-red-700/80" x-text="(tipSeat.occupant.unique_id || '—') + ' · ' + tipSeat.occupant.booking_no"></p>
                            <p class="text-red-700/80" x-text="fmtDate(tipSeat.occupant.from) + ' → ' + fmtDate(tipSeat.occupant.to) + (tipSeat.occupant.start_time ? ' · ' + tipSeat.occupant.start_time + '–' + tipSeat.occupant.end_time : '') + ' · ' + tipSeat.occupant.status"></p>
                            <p class="mt-1 font-semibold" :class="tipSeat.occupant.checked_in ? 'text-emerald-700' : 'text-red-800'" x-text="tipSeat.occupant.checked_in ? '● Checked in' : (tipSeat.occupant.can_check ? 'Click for check-in / booking' : 'Click to open the booking')"></p>
                        </div>
                    </template>
                    <p class="mt-2.5 text-[11px] font-semibold text-brand-700" x-show="tipStatus() === 'available'" x-text="catMeta(tipSeat.category)?.hourly ? 'Click to pick a time slot' : (catMeta(tipSeat.category)?.whole_unit ? 'Click to select the whole cabin' : 'Click to select')"></p>
                    <p class="mt-2.5 text-[11px] font-semibold text-brand-700" x-show="tipStatus() === 'mine'">Click again to remove</p>
                </div>
            </template>
            <template x-if="tip.fac">
                <div class="flex items-start gap-3">
                    <span class="text-2xl" x-text="tip.fac.emoji" aria-hidden="true"></span>
                    <div>
                        <p class="font-bold" x-text="tip.fac.name"></p>
                        <p class="text-xs text-muted" x-text="tip.fac.description"></p>
                        <p class="mt-1 text-xs font-bold" :class="{ included: 'text-emerald-700', addon: 'text-brand-700', landmark: 'text-ink/70' }[tip.fac.kind]"
                           x-text="tip.fac.kind === 'included' ? '✓ Included with every seat' : (tip.fac.kind === 'addon' ? 'Add-on · ' + money(tip.fac.price) + ' / ' + tip.fac.unit : 'Landmark')"></p>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Conference slot picker -->
    <div x-show="slot.open" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="sx-slot-title">
        <div x-show="slot.open" x-transition.opacity class="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" @click="slot.open = false"></div>
        <div x-show="slot.open" x-transition:enter="transition duration-300 ease-[var(--ease-spring)]" x-transition:enter-start="translate-y-10 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             class="relative w-full max-w-xl rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="eyebrow">Hourly booking</p>
                    <h2 id="sx-slot-title" class="mt-1 text-2xl font-extrabold" x-text="byId[slot.unit]?.label || 'Conference room'"></h2>
                    <p class="text-sm text-muted"><span x-text="byId[slot.unit]?.capacity"></span> seats · projector &amp; whiteboard · <span x-text="money(byId[slot.unit]?.rates?.hour || 0)"></span>/hour</p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon -mr-2" @click="slot.open = false" aria-label="Close"><?= icon('x', 'size-5') ?></button>
            </div>
            <label class="mt-5 flex items-center gap-3 rounded-2xl bg-surface px-4 py-2.5 ring-1 ring-line">
                <?= icon('calendar', 'size-5 text-brand-600') ?><span class="text-sm font-semibold text-muted">Date</span>
                <input type="date" x-model="slot.date" :min="cfg.today" @change="loadSlots()" class="ml-auto border-0 bg-transparent p-0 text-sm font-bold focus:ring-0">
            </label>
            <p class="mt-5 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Tap a start hour, then an end hour</p>
            <div class="relative grid grid-cols-3 gap-2 sm:grid-cols-4">
                <template x-for="h in hours" :key="h">
                    <button type="button" class="slot-chip" :data-state="slotState(h)" @click="pickHour(h)" :disabled="['past','booked','held'].includes(slotState(h))"
                            :aria-pressed="['edge','range'].includes(slotState(h)).toString()">
                        <span x-text="hourLabel(h)"></span>
                        <span x-show="slotState(h) === 'booked'" class="absolute top-1 right-1.5 text-[9px] font-bold tracking-wide text-red-500 no-underline uppercase">booked</span>
                        <span x-show="slotState(h) === 'held'" class="absolute top-1 right-1.5 text-[9px] font-bold tracking-wide uppercase">on hold</span>
                    </button>
                </template>
                <div x-show="slot.loading" class="absolute inset-0 grid place-items-center rounded-xl bg-white/70"><span class="size-6 animate-spin rounded-full border-4 border-brand-600 border-t-transparent"></span></div>
            </div>
            <div class="mt-6 flex flex-col gap-3 rounded-2xl bg-surface p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-bold" x-text="slot.start === null ? 'No slot chosen yet' : hourLabel(slot.start) + ' – ' + hourLabel(slot.end) + ' · ' + slotHours + (slotHours === 1 ? ' hour' : ' hours')"></p>
                    <p class="text-sm text-muted" x-text="slot.start === null ? 'Booked hours are striped.' : money(slotPrice) + ' + GST'"></p>
                </div>
                <button type="button" class="btn btn-brand btn-lg" :disabled="slot.start === null" @click="confirmSlot()"><?= icon('check', 'size-5') ?>Hold this slot</button>
            </div>
        </div>
    </div>

    <!-- Sign in prompt (guests) -->
    <div x-show="signIn" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="sx-signin-title">
        <div x-show="signIn" x-transition.opacity class="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" @click="signIn = false"></div>
        <div x-show="signIn" x-transition class="relative w-full max-w-md overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl">
            <div class="bg-gradient-to-br from-brand-700 to-brand-950 p-6 text-white">
                <span class="grid size-12 place-items-center rounded-2xl bg-white/15"><?= icon('armchair', 'size-6') ?></span>
                <h2 id="sx-signin-title" class="mt-4 text-2xl font-extrabold !text-white">Sign in to pick seats</h2>
                <p class="mt-1 text-white/75">We hold your seats for <span x-text="cfg.holdMinutes"></span> minutes while you choose — so we need to know it’s you.</p>
            </div>
            <div class="grid gap-3 p-6">
                <a :href="loginHref(cfg.loginUrl)" class="btn btn-brand btn-lg"><?= icon('log-in', 'size-5') ?>Sign in</a>
                <a :href="cfg.registerUrl" class="btn btn-outline btn-lg"><?= icon('user-plus', 'size-5') ?>Create a free account</a>
                <button type="button" class="btn btn-ghost" @click="signIn = false">Keep browsing</button>
            </div>
        </div>
    </div>

    <!-- Switch category confirm -->
    <div x-show="switchTo" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-6" role="alertdialog" aria-modal="true" aria-labelledby="sx-switch-title">
        <div class="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" @click="switchTo = null"></div>
        <div class="relative w-full max-w-md rounded-t-3xl bg-white p-6 shadow-2xl sm:rounded-3xl">
            <h2 id="sx-switch-title" class="text-xl font-extrabold">Start a new selection?</h2>
            <p class="mt-2 text-muted">A booking covers one space type<span x-show="selCategory === 'CABIN'"> and one cabin</span>. Choosing <b class="text-ink" x-text="switchTo ? (byId[switchTo].kind === 'seat' ? byId[switchTo].code : byId[switchTo].label) : ''"></b> releases your current <span x-text="selUnits.length"></span> held <span x-text="catShort(selCategory)"></span> <span x-text="selUnits.length === 1 ? 'unit' : 'units'"></span>.</p>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn btn-outline" @click="switchTo = null">Keep my selection</button>
                <button type="button" class="btn btn-brand" @click="confirmSwitch()">Switch</button>
            </div>
        </div>
    </div>

<?php if ($staffMode): ?>
    <!-- Reception: occupied seat popover — who sits here, check-in / check-out, open booking -->
    <div x-show="occ.open && occSeat" x-cloak class="fixed inset-0 z-[65]" @click="occ.open = false" @keydown.escape.window="occ.open = false"></div>
    <div x-show="occ.open && occSeat" x-cloak x-transition.opacity class="fixed z-[66] w-80" :style="`left:${occ.x}px;top:${occ.y}px;transform:translate(-50%, ${occ.below ? '0' : '-100%'})`" role="dialog" aria-label="Seat occupant">
        <template x-if="occSeat && occSeat.occupant">
            <div class="rounded-2xl bg-white p-4 shadow-2xl ring-1 ring-line">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-display text-lg font-extrabold" x-text="occSeat.kind === 'seat' ? occSeat.code : occSeat.label"></p>
                        <p class="truncate text-sm font-bold" x-text="occSeat.occupant.name"></p>
                        <p class="font-mono text-xs text-muted" x-text="(occSeat.occupant.unique_id || '—') + ' · ' + occSeat.occupant.booking_no"></p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-icon -mt-1 -mr-2" @click="occ.open = false" aria-label="Close"><?= icon('x', 'size-4') ?></button>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <span class="badge" :class="occSeat.occupant.checked_in ? 'badge-success' : 'badge-neutral'" x-text="occSeat.occupant.checked_in ? 'Checked in' : 'Not checked in'"></span>
                    <span class="badge badge-brand capitalize" x-text="occSeat.occupant.status"></span>
                    <span class="text-muted" x-text="fmtDate(occSeat.occupant.from) + ' → ' + fmtDate(occSeat.occupant.to)"></span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <template x-if="cfg.canCheckin && occSeat.occupant.can_check && !occSeat.occupant.checked_in">
                        <button type="button" class="btn btn-brand btn-sm" :disabled="occ.busy" @click="occToggle('in')"><?= icon('log-in', 'size-4') ?>Check in</button>
                    </template>
                    <template x-if="cfg.canCheckin && occSeat.occupant.can_check && occSeat.occupant.checked_in">
                        <button type="button" class="btn btn-outline btn-sm" :disabled="occ.busy" @click="occToggle('out')"><?= icon('log-out', 'size-4') ?>Check out</button>
                    </template>
                    <a :href="bookingHref(occSeat.occupant.booking_no)" class="btn btn-ghost btn-sm" :class="!(cfg.canCheckin && occSeat.occupant.can_check) && 'col-span-2'"><?= icon('file-text', 'size-4') ?>Open booking</a>
                </div>
                <p x-show="!occSeat.occupant.can_check" class="mt-3 text-xs text-muted">Check-in opens for confirmed bookings on their dates.</p>
            </div>
        </template>
    </div>
<?php endif ?>

<?php if ($staffMode): ?>
    <!-- Reception booking created -->
    <div x-show="booked" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-6" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-brand-950/60 backdrop-blur-sm" @click="booked = null"></div>
        <div class="relative w-full max-w-md rounded-t-3xl bg-white p-6 text-center shadow-2xl sm:rounded-3xl">
            <span class="mx-auto grid size-14 animate-pop place-items-center rounded-full bg-emerald-50 text-emerald-600"><?= icon('party-popper', 'size-7') ?></span>
            <h2 class="mt-4 text-2xl font-extrabold">Booking created</h2>
            <p class="mt-1 text-muted" x-text="booked?.message"></p>
            <p class="mt-3 font-mono text-lg font-bold text-brand-800" x-text="booked?.booking?.booking_no"></p>
            <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                <a :href="booked?.url" class="btn btn-brand"><?= icon('file-text', 'size-4') ?>Open booking</a>
                <button type="button" class="btn btn-outline" @click="booked = null">Book another</button>
            </div>
        </div>
    </div>
<?php endif ?>

    <!-- Toasts -->
    <?= $this->partial('partials/toasts') ?>
</div>
