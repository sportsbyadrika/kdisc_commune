<?php
/**
 * @var App\Core\Template $this
 * @var array<string, list<array<string, mixed>>> $facilities keyed included|addon|landmark
 */
$this->layout('layouts/site');
$groups = [
    ['included', 'Included with every seat', 'No hidden extras — these come with every booking.'],
    ['addons', 'Add-ons', 'Pick these as toggle chips when you choose your seat. Prices exclude GST.'],
    ['landmarks', 'Around the building', 'Shown on the floor map so you always know where things are.'],
];
$map = ['included' => 'included', 'addons' => 'addon', 'landmarks' => 'landmark'];
?>
<?= $this->component('page-hero', [
    'eyebrow' => 'Facilities',
    'title' => 'Everything you need, nothing you don’t',
    'subtitle' => 'Reliable basics included, useful extras when you want them.',
    'breadcrumb' => [['Home', url('home')], ['Facilities']],
]) ?>
<div class="container-page py-16 space-y-16">
    <?php foreach ($groups as [$anchor, $heading, $text]): $items = $facilities[$map[$anchor]] ?? []; ?>
        <section id="<?= e($anchor) ?>">
            <h2 class="section-title !text-2xl sm:!text-3xl"><?= e($heading) ?></h2>
            <p class="lead mt-2"><?= e($text) ?></p>
            <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($items as $f): ?><?= $this->component('facility-tile', ['facility' => $f]) ?><?php endforeach ?>
            </div>
            <?php if ($items === []): ?><?= $this->component('empty', ['title' => 'Nothing here yet', 'icon' => 'sparkles']) ?><?php endif ?>
        </section>
    <?php endforeach ?>
</div>
<?= $this->partial('site/partials/cta') ?>
