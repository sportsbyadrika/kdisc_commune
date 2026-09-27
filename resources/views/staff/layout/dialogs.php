<?php
/**
 * Designer dialogs (inside the layoutDesigner scope): row / grid / cabin / conference room generators, renumber,
 * publish (validation report), discard, conflict. `dlg` holds the open one.
 *
 * @var App\Core\Template $this
 */
?>
<div x-show="dlg" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center p-0 sm:items-center sm:p-6" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-brand-950/50 backdrop-blur-sm" @click="dlg !== 'conflict' && (dlg = null)"></div>

    <!-- row / grid -->
    <form x-show="dlg === 'row' || dlg === 'grid'" @submit.prevent="applyRowGrid()" class="dz-dialog" data-test="dlg-rowgrid">
        <h2 class="text-lg font-extrabold" x-text="dlg === 'row' ? 'Add a row of seats' : 'Add a grid of seats'"></h2>
        <p class="mt-1 text-sm text-muted">Placed in the middle of the view (or the selected zone), auto-numbered per floor and space type.</p>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <label class="dz-field col-span-2"><span>Space type</span>
                <select class="dz-input" x-model="form.category"><template x-for="c in cfg.categories.filter((c) => !c.whole_unit)" :key="c.code"><option :value="c.code" x-text="c.name"></option></template></select></label>
            <template x-if="dlg === 'row'">
                <div class="contents">
                    <label class="dz-field"><span>Seats (N)</span><input type="number" min="1" max="60" class="dz-input" x-model.number="form.count" data-test="row-count"></label>
                    <label class="dz-field"><span>Direction</span><select class="dz-input" x-model="form.dir"><option value="h">Horizontal →</option><option value="v">Vertical ↓</option></select></label>
                    <label class="dz-field col-span-2"><span>Gap between seats (% of plan width)</span><input type="number" min="0" max="20" step="0.1" class="dz-input" x-model.number="form.gap"></label>
                </div>
            </template>
            <template x-if="dlg === 'grid'">
                <div class="contents">
                    <label class="dz-field"><span>Rows</span><input type="number" min="1" max="30" class="dz-input" x-model.number="form.rows"></label>
                    <label class="dz-field"><span>Columns</span><input type="number" min="1" max="30" class="dz-input" x-model.number="form.cols"></label>
                    <label class="dz-field"><span>Horizontal gap %</span><input type="number" min="0" max="20" step="0.1" class="dz-input" x-model.number="form.gapX"></label>
                    <label class="dz-field"><span>Vertical gap %</span><input type="number" min="0" max="20" step="0.1" class="dz-input" x-model.number="form.gapY"></label>
                </div>
            </template>
        </div>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="dlg = null">Cancel</button><button type="submit" class="btn btn-brand" data-test="rowgrid-add"><?= icon('plus', 'size-4') ?>Add <span x-text="dlg === 'row' ? form.count : (form.rows * form.cols)"></span> seats</button></div>
    </form>

    <!-- cabin / conference room -->
    <form x-show="dlg === 'cabin' || dlg === 'room'" @submit.prevent="applyUnit()" class="dz-dialog">
        <h2 class="text-lg font-extrabold" x-text="dlg === 'cabin' ? 'Add an executive cabin' : 'Add a conference room'"></h2>
        <p class="mt-1 text-sm text-muted" x-text="dlg === 'cabin' ? 'Booked as a whole cabin. Creates a Cabin zone, the cabin and its chairs.' : 'Booked by the hour. Creates a Conference zone, the room and chairs around the table.'"></p>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <label class="dz-field"><span>Letter</span><input class="dz-input font-mono uppercase" maxlength="3" x-model="form.letter"></label>
            <label class="dz-field"><span>Chairs (capacity)</span><input type="number" min="1" max="40" class="dz-input" x-model.number="form.chairs"></label>
            <label class="dz-field"><span>Width % of plan</span><input type="number" min="4" max="60" step="0.5" class="dz-input" x-model.number="form.w"></label>
            <label class="dz-field"><span>Height %</span><input type="number" min="4" max="80" step="0.5" class="dz-input" x-model.number="form.h"></label>
        </div>
        <p class="mt-3 rounded-xl bg-surface p-3 font-mono text-xs" x-text="cfg.floor.code + '-' + (dlg === 'cabin' ? 'CB' : 'CF') + '-' + String(form.letter || '').toUpperCase() + '  ·  chairs ' + String(form.letter || '').toUpperCase() + '1…' + String(form.letter || '').toUpperCase() + form.chairs"></p>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="dlg = null">Cancel</button><button type="submit" class="btn btn-brand"><?= icon('plus', 'size-4') ?>Add</button></div>
    </form>

    <!-- renumber -->
    <form x-show="dlg === 'renumber'" @submit.prevent="applyRenumber()" class="dz-dialog">
        <h2 class="text-lg font-extrabold">Renumber <span x-text="selUnits.filter((s) => s.kind === 'seat').length"></span> seats</h2>
        <p class="mt-1 text-sm text-muted">In reading order — row by row, left to right.</p>
        <div class="mt-4 grid grid-cols-3 gap-3">
            <label class="dz-field"><span>Prefix</span><input class="dz-input font-mono uppercase" x-model="form.prefix"></label>
            <label class="dz-field"><span>Start at</span><input type="number" min="0" class="dz-input" x-model.number="form.start"></label>
            <label class="dz-field"><span>Digits</span><input type="number" min="1" max="4" class="dz-input" x-model.number="form.digits"></label>
        </div>
        <p class="mt-3 rounded-xl bg-surface p-3 font-mono text-xs" x-text="form.prefix + String(form.start).padStart(form.digits, '0') + ', ' + form.prefix + String(Number(form.start) + 1).padStart(form.digits, '0') + ', …'"></p>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="dlg = null">Cancel</button><button type="submit" class="btn btn-brand">Renumber</button></div>
    </form>

    <!-- publish -->
    <div x-show="dlg === 'publish'" class="dz-dialog !max-w-2xl" data-test="dlg-publish">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-extrabold">Publish draft v<span x-text="draft?.no"></span> of <span x-text="cfg.floor.name"></span></h2>
                <p class="mt-1 text-sm text-muted">Visitors and reception see the new layout immediately. Existing bookings keep their seats and prices.</p>
            </div>
            <button type="button" class="btn btn-ghost btn-icon size-8" @click="dlg = null" aria-label="Close"><?= icon('x', 'size-4') ?></button>
        </div>
        <div x-show="checkBusy" class="mt-6 flex items-center gap-3 text-sm text-muted"><span class="size-5 animate-spin rounded-full border-2 border-brand-600 border-t-transparent"></span>Checking the layout…</div>
        <template x-if="check">
            <div class="mt-5 max-h-[55vh] space-y-4 overflow-y-auto pr-1">
                <div class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
                    <template x-for="[k, l] in [['units', 'Units'], ['chairs', 'Chairs'], ['added', 'Added'], ['removed', 'Removed'], ['moved', 'Moved'], ['status_changed', 'Status']]" :key="k">
                        <div class="rounded-xl bg-surface px-2 py-2"><p class="text-lg font-extrabold tabular-nums" :class="(k === 'removed' && check.summary[k]) ? 'text-amber-600' : ''" x-text="check.summary[k]"></p><p class="text-[11px] font-semibold text-muted uppercase" x-text="l"></p></div>
                    </template>
                </div>
                <template x-for="(e, i) in check.errors" :key="'e' + i">
                    <div class="rounded-2xl bg-red-50 p-4 ring-1 ring-red-200" data-test="publish-error">
                        <p class="flex gap-2 text-sm font-bold text-red-800"><?= icon('circle-x', 'size-4 mt-0.5 shrink-0') ?><span x-text="e.message"></span></p>
                        <div x-show="e.items.length" class="mt-2 flex flex-wrap gap-1.5 pl-6"><template x-for="it in e.items.slice(0, 40)" :key="it"><button type="button" class="rounded-md bg-white px-2 py-0.5 font-mono text-xs text-red-800 ring-1 ring-red-200 hover:bg-red-100" @click="focusCode(it)" x-text="it"></button></template></div>
                    </div>
                </template>
                <template x-for="(w, i) in check.warnings" :key="'w' + i">
                    <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200" data-test="publish-warning">
                        <p class="flex gap-2 text-sm font-bold text-amber-900"><?= icon('triangle-alert', 'size-4 mt-0.5 shrink-0') ?><span x-text="w.message"></span></p>
                        <ul x-show="w.items.length" class="mt-2 space-y-0.5 pl-6 text-xs text-amber-900"><template x-for="it in w.items" :key="it"><li class="font-mono" x-text="it"></li></template></ul>
                    </div>
                </template>
                <p x-show="!check.errors.length && !check.warnings.length" class="flex items-center gap-2 rounded-2xl bg-emerald-50 p-4 text-sm font-bold text-emerald-800 ring-1 ring-emerald-200"><?= icon('circle-check', 'size-5') ?>All checks passed — ready to publish.</p>
                <label x-show="!check.errors.length && check.warnings.length" class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" class="mt-0.5 size-4 rounded" x-model="confirmWarnings" data-test="confirm-warnings">I have reviewed the warnings above and want to publish anyway.</label>
                <label x-show="!check.errors.length" class="dz-field"><span>Version note (optional)</span><input class="dz-input" maxlength="500" x-model="publishNotes" placeholder="e.g. Added 4 flexi seats near the window"></label>
            </div>
        </template>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="btn btn-ghost" @click="dlg = null">Keep editing</button>
            <button type="button" class="btn btn-brand" @click="publish()" :disabled="busy || !check || check.errors.length > 0 || (check.warnings.length > 0 && !confirmWarnings)" data-test="publish-confirm"><?= icon('cloud-upload', 'size-4') ?>Publish now</button>
        </div>
    </div>

    <!-- discard -->
    <div x-show="dlg === 'discard'" class="dz-dialog">
        <h2 class="text-lg font-extrabold">Discard draft v<span x-text="draft?.no"></span>?</h2>
        <p class="mt-2 text-sm text-muted">All unpublished changes to this floor are thrown away. The live layout is not affected. Prices set on seats that exist only in this draft are removed too.</p>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="dlg = null">Cancel</button><button type="button" class="btn bg-red-600 text-white hover:bg-red-700" @click="discard()" :disabled="busy"><?= icon('trash-2', 'size-4') ?>Discard draft</button></div>
    </div>

    <!-- conflict -->
    <div x-show="dlg === 'conflict'" class="dz-dialog">
        <h2 class="flex items-center gap-2 text-lg font-extrabold"><?= icon('triangle-alert', 'size-5 text-amber-500') ?>The draft changed elsewhere</h2>
        <p class="mt-2 text-sm text-muted" x-text="save.message"></p>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-brand" @click="window.location.reload()"><?= icon('refresh-cw', 'size-4') ?>Reload the latest draft</button></div>
    </div>
</div>
