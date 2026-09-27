<?php
/**
 * Designer inspector — Design tab (inside the layoutDesigner Alpine scope). Every edit calls setField()/change(),
 * i.e. one undoable command + autosave.
 *
 * @var App\Core\Template $this
 */
$statusButtons = [['available', 'Available', 'circle-check'], ['blocked', 'Blocked', 'lock'], ['maintenance', 'Repairs', 'wrench']];
?>
<!-- nothing selected: floor summary -->
<template x-if="!sel.length">
    <div class="space-y-5">
        <div class="grid grid-cols-2 gap-2">
            <template x-for="row in stats" :key="row.cat?.code || 'none'">
                <div class="rounded-xl border border-line p-3">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-muted"><span class="size-2.5 rounded-full" :style="`background:${row.cat?.colour || '#94a3b8'}`"></span><span x-text="row.cat?.short || 'No space type'"></span></p>
                    <p class="mt-1 text-xl font-extrabold tabular-nums" x-text="row.units"></p>
                    <p class="text-xs text-muted"><span x-text="row.chairs"></span> chairs<span x-show="row.blocked" x-text="' · ' + row.blocked + ' blocked'"></span></p>
                </div>
            </template>
        </div>
        <p x-show="dupes.size" class="rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-700 ring-1 ring-red-200">Duplicate codes: <span x-text="[...dupes].join(', ')"></span> — renumber before publishing.</p>
        <div class="rounded-xl bg-surface p-3 text-xs leading-relaxed text-ink/70">
            <p class="mb-1.5 font-bold text-ink">Shortcuts</p>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                <dt><kbd>V</kbd> <kbd>H</kbd></dt><dd>Select · pan</dd>
                <dt><kbd>R</kbd> <kbd>P</kbd></dt><dd>Draw zone rectangle / polygon</dd>
                <dt><kbd>Shift</kbd>-click</dt><dd>Add to selection</dd>
                <dt>Arrows</dt><dd>Nudge (<kbd>Shift</kbd> ×10)</dd>
                <dt><kbd>[</kbd> <kbd>]</kbd></dt><dd>Rotate 15°</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>D</kbd></dt><dd>Duplicate</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>Z</kbd></dt><dd>Undo (<kbd>Shift</kbd> to redo)</dd>
                <dt><kbd>Del</kbd></dt><dd>Delete</dd>
            </dl>
        </div>
    </div>
</template>

<!-- ======================= one seat / cabin / room / chair -->
<template x-if="oneType === 's' && one">
    <div class="space-y-5" :class="!editable && 'dz-readonly'">
        <div class="grid grid-cols-2 gap-2">
            <label class="dz-field"><span>Code</span><input class="dz-input font-mono" :value="one.code" @change="setField('s', 'code', $event.target.value, 'rename')" :disabled="!editable" data-test="f-code"></label>
            <label class="dz-field"><span>Label</span><input class="dz-input" :value="one.label" @change="setField('s', 'label', $event.target.value)" :disabled="!editable" maxlength="50"></label>
        </div>
        <p x-show="dupes.has(one.code)" class="-mt-3 text-xs font-semibold text-red-600">Another seat on this floor uses this code.</p>

        <div x-show="!one.parent_id" class="rounded-xl border border-line p-3">
            <p class="flex items-center justify-between text-xs"><span class="font-bold text-muted uppercase">Zone &amp; space type</span>
                <span class="badge" :class="catOfSeat(one) ? 'badge-brand' : 'badge-danger'" x-text="catOfSeat(one)?.short || 'None'"></span></p>
            <p class="mt-1 text-sm font-semibold" x-text="seatZoneName(one)"></p>
            <select class="dz-input mt-2" @change="moveToZone($event.target.value); $event.target.value = ''" :disabled="!editable">
                <option value="">Move into zone…</option>
                <template x-for="z in doc.zones.filter((z) => z.category_id)" :key="z.id"><option :value="z.id" x-text="z.name + ' · ' + (cat(z.category_id)?.short || '')"></option></template>
            </select>
        </div>

        <div x-show="one.kind !== 'seat'" class="grid grid-cols-2 gap-2">
            <label class="dz-field"><span>Capacity</span><input type="number" min="1" max="99" class="dz-input" :value="one.capacity" @change="setField('s', 'capacity', $event.target.value)" :disabled="!editable"></label>
            <div class="dz-field"><span>Chairs drawn</span><p class="dz-input !bg-surface" :class="childrenOf(one.id).length !== one.capacity && 'text-amber-700'" x-text="childrenOf(one.id).length"></p></div>
        </div>

        <div>
            <p class="dz-h !p-0 !pb-2">Position &amp; size <span class="font-medium tracking-normal normal-case">(% of plan)</span></p>
            <div class="grid grid-cols-4 gap-1.5">
                <?php foreach (['x' => 'X', 'y' => 'Y', 'w' => 'W', 'h' => 'H'] as $k => $l): ?>
                    <label class="dz-field"><span><?= $l ?></span><input type="number" step="0.1" class="dz-input tabular-nums" :value="num(one.<?= $k ?>)" @change="setField('s', '<?= $k ?>', $event.target.value)" :disabled="!editable"></label>
                <?php endforeach ?>
            </div>
            <div class="mt-2 flex items-center gap-2">
                <span class="w-14 text-[11px] font-bold tracking-wide text-muted uppercase">Rotate</span>
                <input type="range" min="0" max="345" step="15" class="flex-1 accent-brand-600" :value="one.rotation" @change="setField('s', 'rotation', $event.target.value)" :disabled="!editable" aria-label="Rotation">
                <input type="number" step="15" class="dz-input !w-16 tabular-nums" :value="one.rotation" @change="setField('s', 'rotation', $event.target.value)" :disabled="!editable" data-test="f-rotation" aria-label="Rotation in degrees">
            </div>
        </div>

        <div x-show="!one.parent_id">
            <p class="dz-h !p-0 !pb-2">Status</p>
            <div class="grid grid-cols-3 gap-1 rounded-xl bg-surface-2 p-1">
                <?php foreach ($statusButtons as [$v, $l, $ico]): ?>
                    <button type="button" class="dz-seg" :class="one.status === '<?= $v ?>' && 'is-on is-<?= $v ?>'" @click="setField('s', 'status', '<?= $v ?>', '<?= $v === 'available' ? 'unblock' : 'block' ?> seat')" :disabled="!editable" data-test="status-<?= $v ?>"><?= icon($ico, 'size-3.5') ?><?= $l ?></button>
                <?php endforeach ?>
            </div>
            <div x-show="one.status !== 'available'" class="mt-2 space-y-2">
                <div class="grid grid-cols-2 gap-2">
                    <label class="dz-field"><span>From (optional)</span><input type="date" class="dz-input" :value="one.status_from || ''" @change="setField('s', 'status_from', $event.target.value || null)" :disabled="!editable"></label>
                    <label class="dz-field"><span>To (optional)</span><input type="date" class="dz-input" :value="one.status_to || ''" @change="setField('s', 'status_to', $event.target.value || null)" :disabled="!editable"></label>
                </div>
                <label class="dz-field"><span>Note (audit-logged)</span><textarea rows="2" maxlength="500" class="dz-input" :value="one.notes || ''" @change="setField('s', 'notes', $event.target.value, 'status note')" placeholder="e.g. Chair broken — replacement ordered" :disabled="!editable" data-test="f-note"></textarea></label>
            </div>
        </div>

        <label x-show="!one.parent_id" class="dz-field"><span>Tags <span class="font-normal text-muted">(comma separated — e.g. window)</span></span><input class="dz-input" :value="one.tags || ''" @change="setField('s', 'tags', $event.target.value)" :disabled="!editable"></label>

        <div x-show="!one.parent_id">
            <p class="dz-h !p-0 !pb-2">Facilities on this <span x-text="one.kind === 'seat' ? 'seat' : one.kind"></span></p>
            <ul class="space-y-1">
                <template x-for="p in attached('seat', one.id)" :key="p.id">
                    <li class="flex items-center gap-2 rounded-lg bg-surface px-2.5 py-1.5 text-sm"><span x-text="fac(p.facility_id)?.emoji"></span><span class="flex-1" x-text="fac(p.facility_id)?.name"></span><span x-show="p.x === null" class="text-[10px] text-muted uppercase">not drawn</span>
                        <button type="button" class="text-muted hover:text-red-600" @click="detach(p)" :disabled="!editable" aria-label="Remove"><?= icon('x', 'size-3.5') ?></button></li>
                </template>
            </ul>
            <div class="mt-2 flex gap-2"><select class="dz-input" x-model="facPick" :disabled="!editable"><option value="">Attach a facility…</option><template x-for="f in facilitiesActive" :key="f.id"><option :value="f.id" x-text="(f.emoji || '') + ' ' + f.name"></option></template></select>
                <button type="button" class="btn btn-outline btn-sm" @click="assignFacility()" :disabled="!facPick || !editable">Add</button></div>
        </div>
    </div>
</template>

<!-- ======================= several items -->
<template x-if="sel.length > 1">
    <div class="space-y-5" :class="!editable && 'dz-readonly'">
        <p class="text-sm text-muted"><b class="text-ink" x-text="selUnits.length"></b> seats/units<span x-show="selZones.length" x-text="', ' + selZones.length + ' zones'"></span><span x-show="selFacs.length" x-text="', ' + selFacs.length + ' facilities'"></span></p>
        <div x-show="sel.filter((r) => r[0] !== 'z').length > 1">
            <p class="dz-h !p-0 !pb-2">Align &amp; distribute</p>
            <div class="flex flex-wrap gap-1">
                <button type="button" class="dz-tool" @click="align('left')" title="Align left" :disabled="!editable"><?= icon('align-start-vertical', 'size-4') ?></button>
                <button type="button" class="dz-tool" @click="align('hcenter')" title="Align centres horizontally" :disabled="!editable"><?= icon('align-center-vertical', 'size-4') ?></button>
                <button type="button" class="dz-tool" @click="align('right')" title="Align right" :disabled="!editable"><?= icon('align-end-vertical', 'size-4') ?></button>
                <button type="button" class="dz-tool" @click="align('top')" title="Align top" :disabled="!editable"><?= icon('align-start-horizontal', 'size-4') ?></button>
                <button type="button" class="dz-tool" @click="align('vcenter')" title="Align middles" :disabled="!editable"><?= icon('align-center-horizontal', 'size-4') ?></button>
                <button type="button" class="dz-tool" @click="align('bottom')" title="Align bottom" :disabled="!editable"><?= icon('align-end-horizontal', 'size-4') ?></button>
                <span class="dz-sep"></span>
                <button type="button" class="dz-tool" @click="distribute('h')" title="Distribute horizontally" :disabled="!editable"><?= icon('align-horizontal-distribute-center', 'size-4') ?></button>
                <button type="button" class="dz-tool" @click="distribute('v')" title="Distribute vertically" :disabled="!editable"><?= icon('align-vertical-distribute-center', 'size-4') ?></button>
            </div>
        </div>
        <div x-show="selUnits.length" class="grid grid-cols-2 gap-2">
            <button type="button" class="btn btn-outline btn-sm" @click="openRenumber()" :disabled="!editable" data-test="renumber"><?= icon('list-ordered', 'size-4') ?>Renumber</button>
            <button type="button" class="btn btn-outline btn-sm" @click="rotateBy(90)" :disabled="!editable"><?= icon('rotate-cw', 'size-4') ?>Rotate 90°</button>
        </div>
        <div x-show="selUnits.length">
            <p class="dz-h !p-0 !pb-2">Status of <span x-text="selUnits.length"></span> units</p>
            <div class="grid grid-cols-3 gap-1 rounded-xl bg-surface-2 p-1">
                <?php foreach ($statusButtons as [$v, $l, $ico]): ?>
                    <button type="button" class="dz-seg" :class="selUnits.every((s) => s.status === '<?= $v ?>') && 'is-on is-<?= $v ?>'" @click="setField('s', 'status', '<?= $v ?>', '<?= $v === 'available' ? 'unblock' : 'block' ?> seats')" :disabled="!editable"><?= icon($ico, 'size-3.5') ?><?= $l ?></button>
                <?php endforeach ?>
            </div>
            <label x-show="selUnits.some((s) => s.status !== 'available')" class="dz-field mt-2"><span>Note for all (audit-logged)</span><textarea rows="2" class="dz-input" @change="setField('s', 'notes', $event.target.value, 'status note')" :disabled="!editable"></textarea></label>
        </div>
        <div x-show="selUnits.length" class="rounded-xl border border-line p-3">
            <p class="dz-h !p-0 !pb-2">Attach a facility to all</p>
            <div class="flex gap-2"><select class="dz-input" x-model="facPick" :disabled="!editable"><option value="">Choose…</option><template x-for="f in facilitiesActive" :key="f.id"><option :value="f.id" x-text="(f.emoji || '') + ' ' + f.name"></option></template></select>
                <button type="button" class="btn btn-outline btn-sm" @click="assignFacility()" :disabled="!facPick || !editable">Add</button></div>
        </div>
        <div class="flex gap-2">
            <button type="button" class="btn btn-outline btn-sm flex-1" @click="duplicate()" :disabled="!editable"><?= icon('copy', 'size-4') ?>Duplicate</button>
            <button type="button" class="btn btn-outline btn-sm flex-1 !text-red-700" @click="remove()" :disabled="!editable"><?= icon('trash-2', 'size-4') ?>Delete</button>
        </div>
    </div>
</template>

<!-- ======================= zone -->
<template x-if="oneType === 'z' && one">
    <div class="space-y-5" :class="!editable && 'dz-readonly'">
        <div class="grid grid-cols-[1fr_6rem] gap-2">
            <label class="dz-field"><span>Name</span><input class="dz-input" :value="one.name" @change="setField('z', 'name', $event.target.value, 'rename zone')" :disabled="!editable" maxlength="100" data-test="z-name"></label>
            <label class="dz-field"><span>Code</span><input class="dz-input font-mono" :value="one.code" @change="setField('z', 'code', $event.target.value)" :disabled="!editable"></label>
        </div>
        <label class="dz-field"><span>Space type</span>
            <select class="dz-input" :value="one.category_id ?? ''" @change="setField('z', 'category_id', $event.target.value, 'change space type')" :disabled="!editable" data-test="z-category">
                <option value="">None — service area (not bookable)</option>
                <template x-for="c in cfg.categories" :key="c.id"><option :value="c.id" :selected="c.id === one.category_id" x-text="c.name"></option></template>
            </select></label>
        <label class="dz-field"><span>Tint colour</span>
            <span class="flex items-center gap-2"><input type="color" class="h-9 w-12 cursor-pointer rounded-lg border border-line bg-white p-1" :value="one.colour || '#64748b'" @change="setField('z', 'colour', $event.target.value, 'zone colour')" :disabled="!editable"><span class="font-mono text-xs text-muted" x-text="one.colour"></span></span></label>
        <div x-show="!one.polygon">
            <p class="dz-h !p-0 !pb-2">Rectangle <span class="font-medium tracking-normal normal-case">(% of plan — or drag the corner handles)</span></p>
            <div class="grid grid-cols-4 gap-1.5">
                <?php foreach (['x' => 'X', 'y' => 'Y', 'w' => 'W', 'h' => 'H'] as $k => $l): ?>
                    <label class="dz-field"><span><?= $l ?></span><input type="number" step="0.5" class="dz-input tabular-nums" :value="num(one.<?= $k ?>)" @change="setField('z', '<?= $k ?>', $event.target.value)" :disabled="!editable"></label>
                <?php endforeach ?>
            </div>
        </div>
        <p x-show="one.polygon" class="rounded-xl bg-surface p-3 text-xs text-muted">Polygon with <b x-text="one.polygon?.length"></b> points — drag the white handles to reshape.</p>
        <p class="text-sm"><b x-text="doc.seats.filter((s) => s.zone_id === one.id && !s.parent_id).length"></b> units inside. Seats take this zone's space type.</p>
        <div>
            <p class="dz-h !p-0 !pb-2">Facilities in this zone</p>
            <ul class="space-y-1">
                <template x-for="p in attached('zone', one.id)" :key="p.id">
                    <li class="flex items-center gap-2 rounded-lg bg-surface px-2.5 py-1.5 text-sm"><span x-text="fac(p.facility_id)?.emoji"></span><span class="flex-1" x-text="fac(p.facility_id)?.name"></span>
                        <button type="button" class="text-muted hover:text-red-600" @click="detach(p)" :disabled="!editable" aria-label="Remove"><?= icon('x', 'size-3.5') ?></button></li>
                </template>
            </ul>
            <div class="mt-2 flex gap-2"><select class="dz-input" x-model="facPick" :disabled="!editable"><option value="">Attach a facility…</option><template x-for="f in facilitiesActive" :key="f.id"><option :value="f.id" x-text="(f.emoji || '') + ' ' + f.name"></option></template></select>
                <button type="button" class="btn btn-outline btn-sm" @click="assignFacility()" :disabled="!facPick || !editable">Add</button></div>
        </div>
        <button type="button" class="btn btn-outline btn-sm w-full !text-red-700" @click="remove()" :disabled="!editable"><?= icon('trash-2', 'size-4') ?>Delete zone</button>
    </div>
</template>

<!-- ======================= facility placement -->
<template x-if="oneType === 'f' && one">
    <div class="space-y-5" :class="!editable && 'dz-readonly'">
        <div class="flex items-center gap-3 rounded-xl border border-line p-3">
            <span class="grid size-11 place-items-center rounded-full bg-surface text-2xl" x-text="fac(one.facility_id)?.emoji || '•'"></span>
            <div><p class="font-bold" x-text="fac(one.facility_id)?.name"></p><p class="text-xs text-muted capitalize" x-text="fac(one.facility_id)?.kind + (fac(one.facility_id)?.kind === 'addon' ? ' · ' + money(fac(one.facility_id)?.price) + '/' + fac(one.facility_id)?.unit : '')"></p></div>
        </div>
        <label class="dz-field"><span>Facility</span>
            <select class="dz-input" @change="setField('f', 'facility_id', $event.target.value, 'change facility')" :disabled="!editable">
                <template x-for="f in cfg.facilities" :key="f.id"><option :value="f.id" :selected="f.id === one.facility_id" x-text="(f.emoji || '') + ' ' + f.name + (f.active ? '' : ' (inactive)')"></option></template>
            </select></label>
        <p class="text-sm">Attached to <b x-text="one.scope === 'floor' ? 'the whole floor' : (one.scope === 'zone' ? 'zone ' + (zone(one.scope_id)?.name || '') : 'seat ' + (item(ref('s', one.scope_id))?.code || ''))"></b>. Drop it on a seat or zone to attach it there.</p>
        <div class="grid grid-cols-2 gap-2">
            <label class="dz-field"><span>X %</span><input type="number" step="0.1" class="dz-input" :value="num(one.x)" @change="setField('f', 'x', $event.target.value)" :disabled="!editable"></label>
            <label class="dz-field"><span>Y %</span><input type="number" step="0.1" class="dz-input" :value="num(one.y)" @change="setField('f', 'y', $event.target.value)" :disabled="!editable"></label>
        </div>
        <button type="button" class="btn btn-outline btn-sm w-full !text-red-700" @click="remove()" :disabled="!editable"><?= icon('trash-2', 'size-4') ?>Remove from plan</button>
    </div>
</template>
