<?php
/**
 * Create / edit a facility. Icon picker = vendored Lucide icons (resources/icons, bin/vendor-js.mjs).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $facility
 * @var list<string> $icons
 */
$editing = isset($facility['id']);
$this->layout('layouts/staff', [
    'title' => $editing ? 'Edit ' . $facility['name'] : 'New facility',
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Facilities', url('staff.facilities.index')], [$editing ? (string) $facility['name'] : 'New']],
]);
$kind = (string) old('kind', (string) ($facility['kind'] ?? 'addon'));
$icon = (string) old('icon', (string) ($facility['icon'] ?? 'package'));
?>
<form method="post" action="<?= e($editing ? url('staff.facilities.update', ['id' => (int) $facility['id']]) : url('staff.facilities.store')) ?>" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_380px]" x-data="{ kind: <?= e(json_encode($kind)) ?>, icon: <?= e(json_encode($icon)) ?>, q: '' }">
    <?= csrf_field() ?>
    <?php if ($editing): ?><?= method_field('PUT') ?><?php endif ?>
    <div class="card space-y-5 p-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
            <?= $this->component('input', ['name' => 'name', 'label' => 'Name', 'value' => $facility['name'] ?? '', 'required' => true, 'attrs' => ['maxlength' => 100]]) ?>
            <?= $this->component('input', ['name' => 'emoji', 'label' => 'Emoji', 'value' => $facility['emoji'] ?? '', 'placeholder' => '🔒', 'attrs' => ['maxlength' => 16]]) ?>
        </div>
        <?= $this->component('input', ['name' => 'description', 'label' => 'Short description', 'value' => $facility['description'] ?? '', 'attrs' => ['maxlength' => 255]]) ?>
        <fieldset>
            <legend class="label">Type</legend>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <?php foreach (App\Enums\FacilityKind::cases() as $k): ?>
                    <label class="flex cursor-pointer items-start gap-2 rounded-2xl border p-3 transition" :class="kind === '<?= $k->value ?>' ? 'border-brand-600 bg-brand-50/60 ring-2 ring-brand-600/15' : 'border-line'">
                        <input type="radio" name="kind" value="<?= $k->value ?>" x-model="kind" class="mt-1">
                        <span><b class="block text-sm"><?= e($k->label()) ?></b><span class="text-xs text-muted"><?= e(['included' => 'Free, with every seat', 'addon' => 'Chargeable, with price & GST', 'landmark' => 'Map marker only'][$k->value]) ?></span></span>
                    </label>
                <?php endforeach ?>
            </div>
        </fieldset>
        <div x-show="kind === 'addon'" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?= $this->component('input', ['name' => 'price', 'label' => 'Price ₹', 'type' => 'number', 'value' => $facility['price'] ?? '', 'attrs' => ['min' => 0, 'step' => '0.01']]) ?>
            <?= $this->component('select', ['name' => 'unit', 'label' => 'Unit', 'options' => App\Enums\FacilityUnit::options(), 'value' => $facility['unit'] ?? 'month']) ?>
            <?= $this->component('input', ['name' => 'stock_qty', 'label' => 'Stock', 'type' => 'number', 'value' => $facility['stock_qty'] ?? '', 'placeholder' => 'Unlimited', 'help' => 'Lockers, parking slots… blank = unlimited', 'attrs' => ['min' => 0]]) ?>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <?= $this->component('input', ['name' => 'gst_rate', 'label' => 'GST %', 'type' => 'number', 'value' => $facility['gst_rate'] ?? 18, 'required' => true, 'attrs' => ['min' => 0, 'max' => 28, 'step' => '0.01']]) ?>
            <?= $this->component('input', ['name' => 'code', 'label' => 'Code', 'value' => $facility['code'] ?? '', 'placeholder' => 'Auto from name', 'attrs' => ['maxlength' => 30]]) ?>
            <?= $this->component('input', ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'value' => $facility['sort_order'] ?? 50, 'attrs' => ['min' => 0, 'max' => 999]]) ?>
        </div>
        <?= $this->component('checkbox', ['name' => 'is_active', 'label' => 'Active', 'checked' => !empty($facility['is_active']), 'help' => 'Inactive facilities disappear from maps and the add-on list; bookings keep them.']) ?>
        <div class="flex justify-end gap-2 border-t border-line pt-5">
            <a href="<?= e(url('staff.facilities.index')) ?>" class="btn btn-ghost">Cancel</a>
            <button class="btn btn-brand" data-test="facility-save"><?= icon('check', 'size-4') ?><?= $editing ? 'Save changes' : 'Create facility' ?></button>
        </div>
    </div>
    <div class="card flex max-h-[80vh] flex-col p-5">
        <div class="flex items-center gap-3">
            <span class="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-700 ring-2 ring-brand-500">
                <?php foreach ($icons as $name): ?><span x-show="icon === '<?= e($name) ?>'" x-cloak><?= icon($name, 'size-6') ?></span><?php endforeach ?>
            </span>
            <div><p class="label !mb-0">Map icon</p><p class="font-mono text-xs text-muted" x-text="icon"></p></div>
        </div>
        <input type="search" x-model="q" placeholder="Search icons…" class="input mt-3" aria-label="Search icons">
        <input type="hidden" name="icon" :value="icon">
        <?php if (errors('icon')): ?><p class="mt-1 text-xs font-semibold text-red-600"><?= e((string) errors('icon')) ?></p><?php endif ?>
        <div class="mt-3 grid min-h-0 flex-1 grid-cols-6 gap-1 overflow-y-auto pr-1">
            <?php foreach ($icons as $name): ?>
                <button type="button" class="grid aspect-square place-items-center rounded-lg text-ink/70 transition hover:bg-surface hover:text-ink" :class="icon === '<?= e($name) ?>' && '!bg-brand-600 !text-white'" x-show="!q || '<?= e($name) ?>'.includes(q.toLowerCase())" @click="icon = '<?= e($name) ?>'" title="<?= e($name) ?>" aria-label="<?= e($name) ?>"><?= icon($name, 'size-5') ?></button>
            <?php endforeach ?>
        </div>
    </div>
</form>
