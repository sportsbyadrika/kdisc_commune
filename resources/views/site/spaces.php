<?php
/**
 * /spaces — building overview and floors. Batch 3 turns each floor into the interactive
 * Space Explorer (SVG seats over the floor image + panzoom).
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $spaceTypes
 * @var list<array<string, mixed>> $floors
 * @var array<string, mixed>|null $building
 * @var string $from
 * @var string $to
 * @var string $type
 * @var list<array<string, mixed>> $included
 */
$this->layout('layouts/site');
?>
<?php $this->begin('page-hero', [
    'eyebrow' => 'Spaces',
    'title' => 'Choose your floor, then your seat',
    'subtitle' => 'Two floors of flexible workspace in the heart of Kottarakara. Open the live seat map to pick your exact desk, cabin or conference slot.',
    'breadcrumb' => [['Home', url('home')], ['Spaces']],
]) ?>
    <a href="<?= e(url('spaces.explore', array_filter(['from' => $from, 'to' => $to, 'type' => $type]))) ?>" class="btn btn-primary btn-lg"><?= icon('map', 'size-4') ?> Open the Space Explorer</a>
    <a href="<?= e(url('pricing')) ?>" class="btn btn-lg bg-white/10 text-white ring-1 ring-white/25 hover:bg-white/20">View pricing</a>
<?= $this->end() ?>

<section id="floors" class="section">
    <div class="container-page space-y-16">
        <?php foreach (array_reverse($floors) as $floor): ?>
            <article id="<?= e($floor['slug']) ?>" class="grid items-center gap-10 lg:grid-cols-[1.4fr_1fr]">
                <div class="card overflow-hidden p-2">
                    <img src="<?= e(media($floor['photo_path'])) ?>" alt="<?= e($floor['name']) ?> plan" class="w-full rounded-[calc(var(--radius-card)-0.5rem)]" loading="lazy">
                </div>
                <div>
                    <p class="eyebrow">Level <?= (int) $floor['level'] ?></p>
                    <h2 class="section-title mt-2"><?= e($floor['name']) ?></h2>
                    <p class="lead mt-3"><?= (int) $floor['total_seats'] ?> seats across <?= count($floor['by_category']) ?> space types.</p>
                    <ul class="mt-6 divide-y divide-line rounded-2xl border border-line">
                        <?php foreach ($floor['by_category'] as $code => $n): $cat = App\Enums\SeatCategory::tryFrom($code); ?>
                            <li class="flex items-center justify-between px-5 py-3.5">
                                <span class="inline-flex items-center gap-3 font-semibold"><span class="text-brand-600"><?= icon($cat?->icon() ?? 'armchair', 'size-5') ?></span><?= e($cat?->label() ?? $code) ?></span>
                                <?= $this->component('badge', ['label' => $n . ' seats', 'tone' => $cat?->tone() ?? 'neutral']) ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="<?= e(url('spaces.floor', array_filter(['floor' => $floor['slug'], 'from' => $from, 'to' => $to]))) ?>" class="btn btn-dark"><?= icon('map', 'size-4') ?> Pick a seat on this floor</a>
                        <a href="<?= e(url('contact')) ?>" class="btn btn-outline">Ask the front desk</a>
                    </div>
                </div>
            </article>
        <?php endforeach ?>
    </div>
</section>

<section class="section bg-surface">
    <div class="container-page">
        <p class="eyebrow">Space types</p>
        <h2 class="section-title mt-2 mb-8">Compare the options</h2>
        <?= $this->partial('site/partials/space-cards') ?>
    </div>
</section>

<?= $this->partial('site/partials/cta') ?>
