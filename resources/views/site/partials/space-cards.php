<?php
/**
 * Grid of space-type cards with Alpine filter chips.
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $spaceTypes from CatalogService::spaceTypes()
 * @var list<array<string, mixed>> $included included facilities
 */
$chips = [['value' => 'all', 'label' => 'All spaces']];
foreach ($spaceTypes as $t) {
    $chips[] = ['value' => (string) $t['code'], 'label' => (string) $t['short_name'], 'icon' => (string) $t['icon']];
}
$facilityIcons = array_map(static fn ($f) => ['icon' => (string) $f['icon'], 'label' => (string) $f['name']], array_slice($included ?? [], 0, 4));
$unitLabel = ['day' => '/day', 'month' => '/month', 'hour' => '/hour'];
?>
<div x-data="filterable('all')">
    <?= $this->component('chips', ['items' => $chips, 'model' => 'filter', 'label' => 'Filter space types', 'class' => 'mb-8']) ?>
    <div class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($spaceTypes as $t):
            $enum = $t['enum'];
            $slug = strtolower((string) $t['short_name']);
            $meta = match (true) {
                $t['code'] === 'CABIN' => $t['units'] . ' cabins · 3 seats each',
                $t['code'] === 'CONF' => $t['chairs'] . ' seats · hourly',
                default => $t['chairs'] . ' seats',
            };
            $unitNote = $t['code'] === 'CABIN' ? '/cabin/month' : ($unitLabel[$t['headline_unit']] ?? '');
        ?>
            <div x-show="shows('<?= e($t['code']) ?>')" x-transition.opacity.duration.200ms id="<?= e($slug) ?>">
                <?= $this->component('card', [
                    'href' => url('spaces') . '#' . $slug,
                    'image' => $t['image_path'],
                    'category' => $t['short_name'],
                    'title' => $t['name'],
                    'text' => $t['description'],
                    'meta' => $meta,
                    'price' => $t['headline_amount'] !== null ? money($t['headline_amount']) : null,
                    'priceUnit' => $unitNote,
                    'facilities' => $t['code'] === 'CONF' ? [['icon' => 'projector', 'label' => 'Projector'], ...array_slice($facilityIcons, 0, 3)] : $facilityIcons,
                    'cta' => $enum?->hourlyOnly() ? 'Book hours' : 'View seats',
                ]) ?>
            </div>
        <?php endforeach ?>
    </div>
</div>
