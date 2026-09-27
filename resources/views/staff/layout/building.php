<?php
/**
 * Building & floors: building photo, floor hotspot polygons on it (Alpine `hotspotEditor`, resources/js/designer.js),
 * floor plan images, add / remove floors. Photos are re-encoded to WebP by PhotoStore; coordinates are % of the image.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed>|null $building
 * @var list<array<string, mixed>> $floorRows BuildingService::floors()
 * @var list<array<string, mixed>> $floors
 */
$this->layout('layouts/staff', [
    'title' => 'Building & floors',
    'wide' => true,
    'hideTitle' => true,
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Layout & pricing', url('staff.layout.index')], ['Building & floors']],
]);
$config = [
    'saveUrl' => url('staff.layout.hotspots'),
    'floors' => array_map(static fn (array $f) => ['id' => (int) $f['id'], 'name' => (string) $f['name'], 'level' => (int) $f['level'], 'hotspot' => $f['hotspot']], $floorRows),
];
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/designer.js')) ?>"></script>
<?php $this->stop() ?>

<div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-3">
    <div>
        <p class="text-[11px] font-bold tracking-[0.16em] text-muted uppercase">Layout &amp; pricing</p>
        <h1 class="text-2xl font-extrabold">Building &amp; floors</h1>
    </div>
    <?= $this->partial('partials/layout/nav', ['active' => 'building']) ?>
</div>

<!-- Hotspot editor -->
<section x-data="hotspotEditor" data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" class="card mb-6 overflow-hidden" data-test="hotspots">
    <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4">
        <div class="min-w-0 flex-1">
            <h2 class="text-lg font-extrabold">Floor hotspots on the building photo</h2>
            <p class="text-sm text-muted">Visitors tap these shapes on <a class="font-semibold text-brand-700 hover:underline" href="<?= e(url('spaces.explore')) ?>" target="_blank" rel="noopener">the building view</a>. Click to add points, click the first point to close, drag to adjust, Alt-click (or right-click) a point to delete it.</p>
        </div>
        <span class="text-sm font-semibold" :class="state === 'dirty' ? 'text-amber-600' : 'text-emerald-600'" x-text="state === 'dirty' ? 'Unsaved changes' : (state === 'saving' ? 'Saving…' : 'Saved ✓')"></span>
        <button type="button" class="btn btn-brand btn-sm" @click="saveAll()" :disabled="state !== 'dirty'" data-test="hotspots-save"><?= icon('save', 'size-4') ?>Save hotspots</button>
    </header>
    <div class="grid gap-0 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div class="bg-brand-950 p-4 sm:p-6">
            <div class="relative mx-auto max-w-4xl select-none" x-ref="photo" @click="addPoint($event)" @contextmenu.prevent>
                <img src="<?= e(media($building['photo_path'] ?? null)) ?>" alt="Building photo" class="block w-full rounded-xl" draggable="false">
                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible" aria-hidden="true">
                    <?php foreach ($floorRows as $i => $f): $F = "floors[{$i}]"; /* x-for does not work inside <svg> */ ?>
                        <polygon x-show="<?= $F ?>.closed" :points="pts(<?= $F ?>)" class="hs-poly" :class="<?= $F ?>.id === active && 'is-on'" @click.stop="active = <?= $F ?>.id"></polygon>
                        <polyline x-show="!<?= $F ?>.closed && <?= $F ?>.hotspot.length" :points="pts(<?= $F ?>)" fill="none" stroke="#e11d74" stroke-width="2" stroke-dasharray="4 3" vector-effect="non-scaling-stroke"></polyline>
                    <?php endforeach ?>
                </svg>
                <template x-for="f in floors" :key="'label' + f.id">
                    <span x-show="f.hotspot.length" class="pointer-events-none absolute left-2 -translate-y-1/2 rounded-full bg-brand-950/75 px-2.5 py-0.5 text-[11px] font-bold text-white ring-1 ring-white/20" :style="`top:${centreY(f)}%`" x-text="f.name"></span>
                </template>
                <template x-if="cur">
                    <div>
                        <template x-for="(p, i) in cur.hotspot" :key="cur.id + '-' + i">
                            <span class="hs-handle" :class="i === 0 && !cur.closed && 'is-first'" :style="`left:${p[0]}%;top:${p[1]}%`" @pointerdown.prevent="pointDown(cur, i, $event)" @click.stop :title="i === 0 && !cur.closed ? 'Click to close the shape' : 'Drag to move · Alt-click to delete'"></span>
                        </template>
                    </div>
                </template>
            </div>
        </div>
        <div class="space-y-3 p-5">
            <template x-for="f in floors" :key="'row' + f.id">
                <div class="rounded-2xl border p-3 transition" :class="f.id === active ? 'border-brand-600 ring-2 ring-brand-600/15' : 'border-line'" @click="active = f.id">
                    <div class="flex items-center gap-2">
                        <input type="radio" name="hs-active" class="size-4" :checked="f.id === active" @change="active = f.id" :aria-label="'Edit ' + f.name">
                        <input class="dz-input flex-1 font-bold" x-model="f.name" @input="touch()" maxlength="100" aria-label="Floor name">
                        <label class="flex items-center gap-1 text-xs font-bold text-muted">Level <input type="number" min="-5" max="60" class="dz-input w-16" x-model.number="f.level" @input="touch()"></label>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs">
                        <span :class="f.closed ? 'text-emerald-700' : 'text-amber-700'" x-text="f.hotspot.length ? (f.closed ? f.hotspot.length + ' points · closed' : f.hotspot.length + ' points · open — click the first point to close') : 'No shape — click on the photo to draw'"></span>
                        <span class="flex gap-1">
                            <button type="button" x-show="!f.closed && f.hotspot.length >= 3" class="btn btn-ghost btn-sm !px-2" @click.stop="active = f.id; close()">Close</button>
                            <button type="button" class="btn btn-ghost btn-sm !px-2" @click.stop="active = f.id; redraw()"><?= icon('rotate-ccw', 'size-3.5') ?>Redraw</button>
                        </span>
                    </div>
                </div>
            </template>
            <p x-show="message" x-text="message" class="rounded-xl bg-surface p-3 text-sm font-semibold"></p>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('staff.layout.building.photo')) ?>" class="rounded-2xl border border-dashed border-line p-3">
                <?= csrf_field() ?>
                <p class="text-sm font-bold">Building photo</p>
                <p class="text-xs text-muted"><?= e((string) ($building['photo_original'] ?? basename((string) ($building['photo_path'] ?? '')))) ?> · <?= (int) ($building['photo_w'] ?? 0) ?> × <?= (int) ($building['photo_h'] ?? 0) ?> px. JPG / PNG / WebP up to 15 MB — optimised to WebP. Hotspots keep their % positions.</p>
                <div class="mt-2 flex gap-2"><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="min-w-0 flex-1 text-xs file:mr-2 file:rounded-full file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-xs file:font-bold">
                    <button class="btn btn-outline btn-sm"><?= icon('upload', 'size-4') ?>Upload</button></div>
                <?php if (errors('photo')): ?><p class="mt-1 text-xs font-semibold text-red-600"><?= e((string) errors('photo')) ?></p><?php endif ?>
            </form>
        </div>
    </div>
</section>

<!-- Floors -->
<section class="card overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
        <div><h2 class="text-lg font-extrabold">Floors &amp; plan images</h2><p class="text-xs text-muted">Seat coordinates are % of the plan image, so a better photo or CAD export can replace a placeholder at any time.</p></div>
    </header>
    <div class="divide-y divide-line">
        <?php foreach ($floorRows as $f): ?>
            <div class="grid items-center gap-4 p-5 lg:grid-cols-[180px_minmax(0,1fr)_auto]" data-test="floor-<?= e((string) $f['slug']) ?>">
                <a href="<?= e(url('staff.layout.floor', ['floor' => (string) $f['slug']])) ?>" class="block overflow-hidden rounded-xl ring-1 ring-line"><img src="<?= e(media((string) $f['photo_path'])) ?>" alt="<?= e((string) $f['name']) ?> plan" class="aspect-[16/10] w-full bg-surface object-cover"></a>
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2"><b class="text-lg"><?= e((string) $f['name']) ?></b> <span class="badge badge-neutral">Level <?= (int) $f['level'] ?></span> <span class="badge badge-brand font-mono">Codes <?= e((string) $f['code']) ?>-…</span>
                        <?php if ($f['published_no'] !== null): ?><span class="badge badge-success">Live v<?= (int) $f['published_no'] ?></span><?php else: ?><span class="badge badge-warning">Not published</span><?php endif ?>
                        <?php if ($f['draft_no'] !== null): ?><span class="badge badge-warning">Draft v<?= (int) $f['draft_no'] ?></span><?php endif ?></p>
                    <p class="mt-1 text-sm text-muted"><?= (int) $f['chairs'] ?> chairs live · plan <?= e((string) ($f['photo_original'] ?? basename((string) $f['photo_path']))) ?> (<?= (int) $f['photo_w'] ?> × <?= (int) $f['photo_h'] ?> px)</p>
                    <form method="post" enctype="multipart/form-data" action="<?= e(url('staff.layout.floors.photo', ['floor' => (int) $f['id']])) ?>" class="mt-3 flex flex-wrap items-center gap-2">
                        <?= csrf_field() ?>
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="min-w-0 text-xs file:mr-2 file:rounded-full file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-xs file:font-bold" aria-label="Plan image for <?= e((string) $f['name']) ?>">
                        <button class="btn btn-outline btn-sm"><?= icon('upload', 'size-4') ?>Replace plan image</button>
                    </form>
                </div>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <a href="<?= e(url('staff.layout.floor', ['floor' => (string) $f['slug']])) ?>" class="btn btn-brand btn-sm"><?= icon('pen-tool', 'size-4') ?>Design</a>
                    <form method="post" action="<?= e(url('staff.layout.floors.destroy', ['floor' => (int) $f['id']])) ?>" x-data @submit="if (!confirm('Remove <?= e((string) $f['name']) ?> and all its layout versions? Floors with booking history cannot be removed.')) $event.preventDefault()">
                        <?= csrf_field() ?><?= method_field('DELETE') ?>
                        <button class="btn btn-ghost btn-sm text-red-700"><?= icon('trash-2', 'size-4') ?>Remove</button>
                    </form>
                </div>
            </div>
        <?php endforeach ?>
    </div>
    <form method="post" action="<?= e(url('staff.layout.floors.store')) ?>" class="grid gap-3 border-t border-line bg-surface/60 p-5 sm:grid-cols-[minmax(0,1fr)_8rem_7rem_auto] sm:items-end">
        <?= csrf_field() ?>
        <label class="dz-field"><span>New floor name</span><input name="name" required maxlength="100" class="dz-input" value="<?= e((string) old('name')) ?>" placeholder="e.g. Second Floor"></label>
        <label class="dz-field"><span>Code prefix</span><input name="code" required maxlength="5" class="dz-input font-mono uppercase" value="<?= e((string) old('code')) ?>" placeholder="S"></label>
        <label class="dz-field"><span>Level</span><input name="level" type="number" min="-5" max="60" required class="dz-input" value="<?= e((string) old('level', '2')) ?>"></label>
        <button class="btn btn-outline"><?= icon('plus', 'size-4') ?>Add floor</button>
        <?php foreach (['name', 'code', 'level'] as $k): if (errors($k)): ?><p class="text-xs font-semibold text-red-600 sm:col-span-4"><?= e((string) errors($k)) ?></p><?php endif; endforeach ?>
    </form>
</section>
