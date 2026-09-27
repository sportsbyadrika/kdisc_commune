<?php
/**
 * Space Explorer — Level 1 (building). Hotspot polygons come from floors.hotspot_polygon (percent of the
 * building photo); free counts are live for the chosen dates (GET /api/space/building).
 *
 * @var App\Core\Template $this
 * @var array{from: string, to: string, type: string} $filters
 * @var array<string, mixed>|null $building
 * @var list<array<string, mixed>> $floors
 * @var list<array<string, mixed>> $categories
 */
$this->layout('layouts/site', ['description' => 'Explore Commune Kottarakara floor by floor and pick your seat with live availability.']);
$config = [
    'api' => url('/api/space'),
    'floors' => $floors,
    'filters' => $filters,
    'today' => date('Y-m-d'),
    'floorUrl' => url('spaces.floor', ['floor' => '__FLOOR__']),
    'categories' => $categories,
];
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/explorer.js')) ?>"></script>
<?php $this->stop() ?>

<section x-data="buildingExplorer" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" class="relative isolate overflow-hidden bg-brand-950 text-white">
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(60rem_30rem_at_110%_-20%,var(--color-accent-500)_0%,transparent_55%),radial-gradient(50rem_30rem_at_-10%_120%,var(--color-brand-600)_0%,transparent_60%)] opacity-50"></div>
    <div class="container-page pt-8 pb-14 sm:pt-10 lg:pb-20">
        <?= $this->component('breadcrumb', ['items' => [['Home', url('home')], ['Space Explorer']], 'inverse' => true]) ?>
        <div class="mt-5 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="eyebrow !text-accent-400">Space Explorer</p>
                <h1 class="mt-2 max-w-2xl font-display text-4xl font-extrabold !text-white sm:text-5xl">Pick a floor, then your seat</h1>
                <p class="mt-3 max-w-xl text-lg text-white/70">Live availability for your dates. Hover a floor to see what’s free — tap it to open the seat map.</p>
            </div>
            <!-- Filter bar -->
            <div class="glass-dark grid gap-3 rounded-3xl p-3 sm:grid-cols-[auto_auto_auto] sm:items-center sm:rounded-full sm:pl-5">
                <label class="flex items-center gap-2 text-sm"><span class="text-xs font-bold tracking-wide text-white/60 uppercase">From</span>
                    <input type="date" x-model="from" :min="today" @change="fromChanged()" class="border-0 bg-transparent p-1 font-semibold text-white [color-scheme:dark] focus:ring-0"></label>
                <label class="flex items-center gap-2 text-sm sm:border-l sm:border-white/15 sm:pl-4"><span class="text-xs font-bold tracking-wide text-white/60 uppercase">To</span>
                    <input type="date" x-model="to" :min="from" @change="refresh()" class="border-0 bg-transparent p-1 font-semibold text-white [color-scheme:dark] focus:ring-0"></label>
                <label class="flex items-center gap-2 text-sm sm:border-l sm:border-white/15 sm:pl-4"><span class="sr-only">Space type</span>
                    <select x-model="type" @change="refresh()" class="rounded-full border-0 bg-white/10 py-2 pr-9 pl-4 text-sm font-semibold text-white focus:ring-2 focus:ring-accent-400 [&>option]:text-ink">
                        <option value="">All space types</option>
                        <?php foreach ($categories as $c): ?><option value="<?= e($c['code']) ?>"><?= e($c['label']) ?></option><?php endforeach ?>
                    </select></label>
            </div>
        </div>

        <div class="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:items-start">
            <!-- Building with floor hotspots -->
            <div class="relative overflow-hidden rounded-[2rem] shadow-2xl ring-1 ring-white/10" @mouseleave="hover = null">
                <img src="<?= e(media($building['photo_path'] ?? null)) ?>" alt="<?= e((string) ($building['name'] ?? 'Commune building')) ?>" class="block w-full select-none" draggable="false">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full" role="group" aria-label="Floors">
                    <?php foreach ($floors as $i => $f): $pts = implode(' ', array_map(static fn ($p) => $p[0] . ',' . $p[1], $f['hotspot'])); ?>
                        <a href="<?= e(url('spaces.floor', ['floor' => $f['slug'], 'from' => $filters['from'], 'to' => $filters['to']])) ?>" :href="href(floors[<?= $i ?>])" @click="tap(floors[<?= $i ?>], $event)" @focus="enter(floors[<?= $i ?>])" @blur="hover = null" aria-label="<?= e($f['name']) ?>">
                            <polygon points="<?= e($pts) ?>" vector-effect="non-scaling-stroke" stroke-width="2" class="cursor-pointer transition duration-300"
                                     :class="(hover === '<?= e($f['slug']) ?>' || pinned === '<?= e($f['slug']) ?>') ? 'fill-accent-500/30 stroke-white' : 'fill-white/0 stroke-white/0 hover:fill-white/10'"
                                     @mouseenter="enter(floors[<?= $i ?>])"></polygon>
                        </a>
                    <?php endforeach ?>
                </svg>
                <!-- floor pills (always visible) -->
                <template x-for="f in floors" :key="'pill' + f.slug">
                    <span class="pointer-events-none absolute left-3 -translate-y-1/2 rounded-full bg-brand-950/75 px-3 py-1 text-xs font-bold text-white shadow-lg ring-1 ring-white/20 backdrop-blur sm:left-4"
                          :style="`top:${(Math.min(...f.hotspot.map(p => p[1])) + Math.max(...f.hotspot.map(p => p[1]))) / 2}%`"
                          x-text="f.name.replace(' Floor', '')"></span>
                </template>
                <!-- glass tooltip -->
                <div x-show="active" x-cloak x-transition.opacity.duration.200ms class="absolute z-10 w-64 -translate-x-1/2" :style="`left:${tipX}%;top:calc(${tipY}% + 10px)`">
                    <div class="glass rounded-2xl p-4 text-ink">
                        <template x-if="active">
                            <div>
                                <p class="flex items-baseline justify-between gap-2"><span class="font-display text-lg font-extrabold" x-text="active.name"></span><span class="text-xs font-semibold text-muted" x-text="'Level ' + active.level"></span></p>
                                <p class="mt-0.5 text-sm"><b x-text="total(active)"></b> seats · <b class="text-emerald-600" x-text="free(active)"></b> free</p>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full bg-emerald-500 transition-all duration-500" :style="`width:${pct(active)}%`"></div></div>
                                <div class="mt-3 grid grid-cols-2 gap-1.5 text-xs">
                                    <template x-for="(v, code) in active.by_category" :key="code">
                                        <span class="flex justify-between rounded-lg bg-white/70 px-2 py-1 ring-1 ring-line/70"><span class="font-semibold" x-text="catLabel(code)"></span><span><b class="text-emerald-600" x-text="v.free"></b>/<span x-text="v.chairs"></span></span></span>
                                    </template>
                                </div>
                                <a :href="href(active)" class="btn btn-brand btn-sm pointer-events-auto mt-3 w-full">Open seat map <?= icon('arrow-right', 'size-4') ?></a>
                            </div>
                        </template>
                    </div>
                </div>
                <div x-show="loading" class="absolute inset-0 grid place-items-center bg-brand-950/30"><span class="size-8 animate-spin rounded-full border-4 border-white border-t-transparent"></span></div>
            </div>

            <!-- Floor cards -->
            <div class="space-y-4">
                <template x-for="f in floors" :key="'card' + f.slug">
                    <a :href="href(f)" @mouseenter="enter(f)" @mouseleave="hover = null"
                       class="group block rounded-3xl p-5 ring-1 transition duration-300" :class="hover === f.slug ? 'bg-white text-ink ring-white shadow-2xl -translate-y-0.5' : 'bg-white/5 ring-white/10 hover:bg-white/10'">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold tracking-[0.16em] uppercase" :class="hover === f.slug ? 'text-accent-500' : 'text-white/50'" x-text="'Level ' + f.level"></p>
                                <h2 class="mt-1 text-2xl font-extrabold" :class="hover === f.slug ? '!text-ink' : '!text-white'" x-text="f.name"></h2>
                            </div>
                            <div class="text-right">
                                <p class="font-display text-3xl font-extrabold tabular-nums" :class="hover === f.slug ? 'text-emerald-600' : 'text-emerald-400'" x-text="free(f)"></p>
                                <p class="text-xs" :class="hover === f.slug ? 'text-muted' : 'text-white/60'" x-text="'of ' + total(f) + ' free'"></p>
                            </div>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full" :class="hover === f.slug ? 'bg-surface-2' : 'bg-white/10'"><div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-500 transition-all duration-700" :style="`width:${pct(f)}%`"></div></div>
                        <div class="mt-4 flex flex-wrap gap-1.5">
                            <template x-for="(v, code) in f.by_category" :key="code">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="[hover === f.slug ? 'bg-surface text-ink/80' : 'bg-white/10 text-white/80', type && type !== code ? 'opacity-40' : ''].join(' ')" x-text="catLabel(code) + ' · ' + v.free + ' free'"></span>
                            </template>
                        </div>
                        <p class="mt-4 inline-flex items-center gap-1.5 text-sm font-bold" :class="hover === f.slug ? 'text-brand-700' : 'text-white'">Pick seats <?= icon('arrow-right', 'size-4 transition group-hover:translate-x-1') ?></p>
                    </a>
                </template>
                <p class="flex items-center gap-2 text-xs text-white/50"><?= icon('info', 'size-4') ?>Counts are chairs free for every day of your dates. Cabins and the conference room are booked whole.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container-page">
        <p class="eyebrow">Space types</p>
        <h2 class="section-title mt-2 mb-8">What you can book</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($categories as $c): ?>
                <a href="<?= e(url('spaces.floor', ['floor' => 'ground-floor', 'from' => $filters['from'], 'to' => $filters['to'], 'type' => $c['code']])) ?>" class="card card-hover card-body group">
                    <span class="grid size-11 place-items-center rounded-2xl bg-brand-50 text-brand-700 transition group-hover:bg-brand-600 group-hover:text-white"><?= icon((string) $c['icon'], 'size-5') ?></span>
                    <h3 class="mt-4 text-lg font-bold"><?= e($c['label']) ?></h3>
                    <p class="mt-1 text-sm text-muted"><?= e(implode(' · ', array_map(static fn ($u, $a) => money($a) . '/' . $u, array_keys($c['rates']), $c['rates']))) ?></p>
                    <p class="mt-3 text-xs font-semibold text-ink/60"><?= e($c['whole_unit'] ? ($c['hourly'] ? 'Whole room, by the hour' : 'Whole cabin only') : 'Per seat · pick several') ?></p>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</section>
