<?php
/**
 * Layout & Pricing Designer (spec 5.4) — Figma-lite canvas editor for one floor. Behaviour: resources/js/designer.js
 * (Alpine `layoutDesigner`), drawing shared with the Space Explorer via resources/js/space-render.js.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $floor
 * @var list<array<string, mixed>> $floors
 * @var array<string, mixed> $config  DesignerPresenter::config()
 */
$this->layout('layouts/staff', [
    'title' => 'Layout designer',
    'wide' => true,
    'hideTitle' => true,
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Layout & pricing', url('staff.layout.index')], [(string) $floor['name']]],
]);
$canPrice = (bool) $config['canPrice'];
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/vendor/panzoom.min.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/designer.js')) ?>"></script>
<?php $this->stop() ?>

<div x-data="layoutDesigner" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" class="dz">
    <?= $this->partial('partials/space/sprite', ['extra' => array_merge(array_column($config['facilities'], 'icon'), ['ban', 'wrench'])]) ?>

    <!-- Title + section nav -->
    <div class="mb-4 flex flex-wrap items-end gap-x-6 gap-y-3">
        <div class="min-w-0">
            <p class="text-[11px] font-bold tracking-[0.16em] text-muted uppercase">Layout &amp; pricing</p>
            <h1 class="text-2xl font-extrabold">Floor plan designer</h1>
        </div>
        <div class="ml-auto min-w-0 max-w-full"><?= $this->partial('partials/layout/nav', ['active' => 'floor', 'floorSlug' => (string) $floor['slug']]) ?></div>
    </div>

    <div class="dz-shell">
        <!-- top bar: floors · version · save state · actions -->
        <div class="dz-topbar">
            <nav class="flex shrink-0 rounded-full bg-surface-2 p-1" aria-label="Floors">
                <?php foreach ($floors as $f): $on = $f['slug'] === $floor['slug']; ?>
                    <a href="<?= e(url('staff.layout.floor', ['floor' => (string) $f['slug']])) ?>" class="<?= $on ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' ?> inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[13px] font-bold whitespace-nowrap transition" <?= $on ? 'aria-current="page"' : '' ?>><?= icon('layers', 'size-3.5') ?><?= e(str_replace(' Floor', '', (string) $f['name'])) ?></a>
                <?php endforeach ?>
            </nav>
            <span x-show="draft" x-cloak class="badge badge-warning" x-text="'Draft v' + draft?.no + (cfg.published ? ' · based on live v' + cfg.published.no : '')"></span>
            <span x-show="!draft && cfg.published" class="badge badge-success" x-text="'Live v' + cfg.published?.no"></span>
            <span x-show="draft" x-cloak class="dz-save" :data-state="save.state" :title="save.message || ''" aria-live="polite" data-test="save-state">
                <span class="dz-save-dot"></span><span x-text="saveLabel"></span>
            </span>
            <div class="ml-auto flex items-center gap-1.5">
                <a :href="cfg.urls.history" class="btn btn-ghost btn-sm hidden lg:inline-flex"><?= icon('history', 'size-4') ?>History</a>
                <button type="button" class="btn btn-outline btn-sm" @click="preview()" data-test="preview"><?= icon('eye', 'size-4') ?>Preview</button>
                <template x-if="draft">
                    <div class="flex gap-1.5">
                        <button type="button" class="btn btn-ghost btn-sm text-red-700" @click="dlg = 'discard'"><?= icon('trash-2', 'size-4') ?><span class="hidden sm:inline">Discard</span></button>
                        <button type="button" class="btn btn-brand btn-sm" @click="openPublish()" data-test="publish"><?= icon('cloud-upload', 'size-4') ?>Publish…</button>
                    </div>
                </template>
                <template x-if="!draft">
                    <button type="button" class="btn btn-brand btn-sm" @click="startDraft()" :disabled="busy" data-test="start-draft"><?= icon('pencil', 'size-4') ?>Edit layout</button>
                </template>
            </div>
        </div>

    <div class="dz-body" :class="{ 'is-left': leftOpen, 'is-right': rightOpen }">
        <!-- ============================== Left: palette, facilities, zones -->
        <aside class="dz-left dz-panel" aria-label="Add to plan">
            <div class="flex items-center justify-between px-4 pt-4 xl:hidden"><p class="dz-h">Add to plan</p><button type="button" class="btn btn-ghost btn-icon size-8" @click="leftOpen = false" aria-label="Close panel"><?= icon('x', 'size-4') ?></button></div>
            <p class="dz-h hidden px-4 pt-4 xl:block">Add to plan</p>
            <div class="grid gap-2 px-3 pb-2" :class="!editable && 'opacity-50'" :inert="!editable">
                <button type="button" class="dz-palette" draggable="true" @dragstart="paletteDrag($event, { type: 'seat' })" @click="addSeatAtCentre()" data-test="palette-seat">
                    <span class="dz-palette-ico bg-emerald-500 text-white"><?= icon('armchair', 'size-4') ?></span>
                    <span class="min-w-0 flex-1"><span class="block">Seat</span><span class="dz-palette-sub">Drag onto the plan</span></span>
                    <?= icon('grip-horizontal', 'size-4 text-muted') ?>
                </button>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="dz-palette-sm" @click="openDialog('row')" data-test="add-row"><?= icon('rows-3', 'size-4 text-brand-600') ?>Row of N</button>
                    <button type="button" class="dz-palette-sm" @click="openDialog('grid')"><?= icon('grid-3x3', 'size-4 text-brand-600') ?>Grid R×C</button>
                    <button type="button" class="dz-palette-sm" @click="openDialog('cabin')"><?= icon('door-open', 'size-4 text-violet-600') ?>Cabin</button>
                    <button type="button" class="dz-palette-sm" @click="openDialog('room')"><?= icon('presentation', 'size-4 text-amber-600') ?>Conf. room</button>
                    <button type="button" class="dz-palette-sm" @click="setTool('rect')" :class="tool === 'rect' && 'is-on'"><?= icon('square-dashed', 'size-4 text-slate-500') ?>Zone ▭</button>
                    <button type="button" class="dz-palette-sm" @click="setTool('poly')" :class="tool === 'poly' && 'is-on'"><?= icon('pentagon', 'size-4 text-slate-500') ?>Zone ⬠</button>
                </div>
            </div>

            <div class="flex items-center justify-between px-4 pt-4"><p class="dz-h !p-0">Facilities</p><a href="<?= e(url('staff.facilities.index')) ?>" class="text-xs font-semibold text-brand-700 hover:underline">Manage</a></div>
            <p class="px-4 pb-2 text-xs text-muted">Drag onto a seat, a zone or the floor.</p>
            <div class="px-3 pb-2" :class="!editable && 'opacity-50'" :inert="!editable">
                <template x-for="[kind, title] in [['addon', 'Add-ons'], ['included', 'Included'], ['landmark', 'Landmarks']]" :key="kind">
                    <div class="mb-1">
                        <p class="px-2 pt-1.5 pb-0.5 text-[10px] font-bold tracking-wider text-muted/80 uppercase" x-text="title"></p>
                        <template x-for="f in facilitiesActive.filter((x) => x.kind === kind)" :key="f.id">
                            <div class="dz-fac" draggable="true" @dragstart="paletteDrag($event, { type: 'facility', id: f.id })" :data-kind="f.kind" :title="'Drag ' + f.name + ' onto the plan'" :data-test="'fac-' + f.code">
                                <span class="dz-fac-ico" x-text="f.emoji || '•'"></span>
                                <span class="min-w-0 flex-1 truncate" x-text="f.name"></span>
                                <span x-show="f.kind === 'addon'" class="text-[11px] font-bold text-brand-700 tabular-nums" x-text="money(f.price)"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <p class="dz-h px-4 pt-4">Zones</p>
            <ul class="grid gap-0.5 px-3 pb-3">
                <template x-for="z in doc.zones" :key="z.id">
                    <li><button type="button" class="dz-layer" :class="isSel('z', z.id) && 'is-on'" @click="sel = [ref('z', z.id)]; render()">
                        <span class="size-3 shrink-0 rounded-sm" :style="`background:${z.colour || cat(z.category_id)?.colour || '#94a3b8'}`"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="z.name"></span>
                        <span class="text-xs text-muted" x-text="cat(z.category_id)?.short || '—'"></span>
                    </button></li>
                </template>
            </ul>

            <div class="mt-auto border-t border-line p-4">
                <p class="dz-h !p-0">Plan image</p>
                <p class="mt-1 text-xs text-muted"><?= (int) $config['floor']['width'] ?> × <?= (int) $config['floor']['height'] ?> px. Seats keep their % positions when you replace it.</p>
                <form method="post" enctype="multipart/form-data" action="<?= e(url('staff.layout.floors.photo', ['floor' => (int) $floor['id']])) ?>" class="mt-2 flex items-center gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= e(url('staff.layout.floor', ['floor' => (string) $floor['slug']])) ?>">
                    <label class="btn btn-outline btn-sm min-w-0 flex-1 cursor-pointer"><?= icon('image', 'size-4') ?><span class="truncate">Replace…</span>
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="$el.form.submit()"></label>
                </form>
            </div>
        </aside>

        <!-- ============================== Canvas -->
        <section class="dz-canvas" aria-label="Floor plan canvas">
            <div class="dz-viewport" x-ref="viewport" :class="'tool-' + tool" data-test="viewport">
                <div class="map-stage" x-ref="stage">
                    <img src="<?= e((string) $config['floor']['image']) ?>" alt="<?= e((string) $floor['name']) ?> plan" draggable="false">
                    <svg x-ref="svg" xmlns="http://www.w3.org/2000/svg" aria-label="Seats and zones of <?= e((string) $floor['name']) ?>"></svg>
                </div>
            </div>

            <!-- floating toolbar -->
            <div class="dz-toolbar" role="toolbar" aria-label="Tools">
                <button type="button" class="dz-tool xl:hidden" @click="leftOpen = !leftOpen" :aria-pressed="leftOpen.toString()" title="Add to plan"><?= icon('square-plus', 'size-[18px]') ?></button>
                <span class="dz-sep xl:hidden"></span>
                <button type="button" class="dz-tool" :aria-pressed="(tool === 'select').toString()" @click="setTool('select')" title="Select (V) — Shift-click adds, drag on empty space to lasso"><?= icon('mouse-pointer-2', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" :aria-pressed="(tool === 'hand').toString()" @click="setTool('hand')" title="Pan (H or hold Space)"><?= icon('hand', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" :aria-pressed="(tool === 'rect').toString()" @click="setTool('rect')" :disabled="!editable" title="Draw a rectangular zone (R)"><?= icon('square-dashed', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" :aria-pressed="(tool === 'poly').toString()" @click="setTool('poly')" :disabled="!editable" title="Draw a polygon zone (P) — click points, click the first point or press Enter to close"><?= icon('pentagon', 'size-[18px]') ?></button>
                <span class="dz-sep"></span>
                <button type="button" class="dz-tool" @click="undo()" :disabled="!undoStack.length" :title="undoLabel" data-test="undo"><?= icon('undo-2', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" @click="redo()" :disabled="!redoStack.length" :title="redoLabel" data-test="redo"><?= icon('redo-2', 'size-[18px]') ?></button>
                <span class="dz-sep"></span>
                <button type="button" class="dz-tool" @click="rotateBy(90)" :disabled="!editable || !selUnits.length" title="Rotate 90° ( [ / ] rotate 15°)" data-test="rotate"><?= icon('rotate-cw', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" @click="duplicate()" :disabled="!editable || !sel.length" title="Duplicate (Ctrl+D)"><?= icon('copy', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool hover:!text-red-600" @click="remove()" :disabled="!editable || !sel.length" title="Delete (Del)"><?= icon('trash-2', 'size-[18px]') ?></button>
                <span class="dz-sep"></span>
                <button type="button" class="dz-tool" :aria-pressed="snap.toString()" @click="snap = !snap" title="Snap to grid" data-test="snap"><?= icon('magnet', 'size-[18px]') ?></button>
                <label class="sr-only" for="dz-grid">Grid size</label>
                <select id="dz-grid" x-model.number="gridPx" class="dz-select" :disabled="!snap" title="Grid size (image pixels)">
                    <option value="8">8 px</option><option value="16">16 px</option><option value="24">24 px</option><option value="32">32 px</option>
                </select>
                <span class="dz-sep xl:hidden"></span>
                <button type="button" class="dz-tool xl:hidden" @click="rightOpen = !rightOpen" :aria-pressed="rightOpen.toString()" title="Inspector"><?= icon('panel-left', 'size-[18px] rotate-180') ?></button>
            </div>

            <!-- read-only banner -->
            <div x-show="!draft && !sel.length" x-cloak x-transition.opacity class="dz-banner">
                <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><?= icon('eye', 'size-5') ?></span>
                <div class="min-w-0 text-sm"><p class="font-bold">Live layout<span x-show="cfg.published" x-text="' · version ' + cfg.published?.no"></span> — read only</p><p class="hidden text-muted 2xl:block">Edits happen on a draft and go live when you publish. Prices can be set here too.</p><p class="text-muted 2xl:hidden">Prices can be set here too.</p></div>
                <button type="button" class="btn btn-brand btn-sm shrink-0" @click="startDraft()" :disabled="busy"><?= icon('pencil', 'size-4') ?>Edit layout</button>
            </div>
            <div x-show="tool === 'poly' && editable" x-cloak class="dz-hint-top">Click to add points · click the first point or press Enter to close · Esc cancels</div>
            <div x-show="tool === 'rect' && editable" x-cloak class="dz-hint-top">Drag to draw a zone</div>

            <!-- zoom -->
            <div class="dz-zoom">
                <button type="button" class="dz-tool" @click="zoomOut()" title="Zoom out (−)"><?= icon('zoom-out', 'size-[18px]') ?></button>
                <button type="button" class="w-14 text-center text-xs font-bold tabular-nums" @click="zoomReset()" title="Fit (0)" x-text="Math.round(scale * 100) + '%'"></button>
                <button type="button" class="dz-tool" @click="zoomIn()" title="Zoom in (+)"><?= icon('zoom-in', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" @click="zoomToSel()" :disabled="!sel.length" title="Zoom to selection (Shift+2)" data-test="zoom-sel"><?= icon('locate-fixed', 'size-[18px]') ?></button>
                <button type="button" class="dz-tool" @click="zoomReset()" title="Fit to screen (0)"><?= icon('maximize', 'size-[18px]') ?></button>
            </div>
            <p class="dz-hint"><?= icon('keyboard', 'size-3.5') ?>Shift-click · drag empty space to lasso · arrows nudge (Shift ×10) · Space-drag pans · Ctrl+scroll zooms</p>
        </section>

        <!-- ============================== Right: inspector -->
        <aside class="dz-right dz-panel" aria-label="Inspector" data-test="inspector">
            <div class="sticky top-0 z-10 border-b border-line bg-white/95 px-4 pt-4 pb-3 backdrop-blur">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="dz-h !p-0" x-text="!sel.length ? 'Floor' : (sel.length === 1 ? ({ s: one?.kind === 'seat' ? (one?.parent_id ? 'Chair' : 'Seat') : (one?.kind === 'room' ? 'Conference room' : 'Cabin'), z: 'Zone', f: 'Facility' }[oneType]) : 'Selection')"></p>
                        <h2 class="truncate text-lg font-extrabold" x-text="selTitle"></h2>
                    </div>
                    <button type="button" class="btn btn-ghost btn-icon size-8 shrink-0 xl:hidden" @click="rightOpen = false" aria-label="Close inspector"><?= icon('x', 'size-4') ?></button>
                </div>
                <div class="mt-3 flex rounded-full bg-surface-2 p-1 text-sm font-bold" role="tablist">
                    <button type="button" role="tab" class="flex-1 rounded-full py-1.5 transition" :class="tab === 'design' ? 'bg-white shadow-sm' : 'text-muted'" @click="tab = 'design'" :aria-selected="(tab === 'design').toString()">Design</button>
                    <button type="button" role="tab" class="flex-1 rounded-full py-1.5 transition" :class="tab === 'pricing' ? 'bg-white shadow-sm' : 'text-muted'" @click="tab = 'pricing'; syncPriceForm()" :aria-selected="(tab === 'pricing').toString()" data-test="tab-pricing">Pricing</button>
                </div>
            </div>

            <div class="flex-1 px-4 py-4" x-show="tab === 'design'">
                <?= $this->partial('staff/layout/inspector-design') ?>
            </div>
            <div class="flex-1 px-4 py-4" x-show="tab === 'pricing'" x-cloak>
                <?= $this->partial('staff/layout/inspector-pricing', ['canPrice' => $canPrice]) ?>
            </div>
        </aside>
    </div>
    </div>

    <?= $this->partial('staff/layout/dialogs') ?>
    <?= $this->partial('partials/toasts', ['position' => 'bottom']) ?>
</div>
