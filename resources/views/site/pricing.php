<?php
/**
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $spaceTypes
 * @var list<array<string, mixed>> $addons
 */
$this->layout('layouts/site');
$gst = (float) setting('gst_rate', 18);
$advanceMonths = (int) setting('advance_max_months', 6);
$unitNames = ['day' => 'per day', 'month' => 'per month', 'hour' => 'per hour'];
?>
<?= $this->component('page-hero', [
    'eyebrow' => 'Pricing',
    'title' => 'Simple, transparent pricing',
    'subtitle' => 'Pay only for what you use — by the day, month or hour. All prices are exclusive of GST (' . rtrim(rtrim(number_format($gst, 2), '0'), '.') . '%).',
    'breadcrumb' => [['Home', url('home')], ['Pricing']],
]) ?>

<section class="section">
    <div class="container-page">
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($spaceTypes as $i => $t): $featured = $t['code'] === 'DEDICATED'; ?>
                <div class="<?= e(class_names('card relative flex flex-col p-7', ['ring-2 ring-brand-600' => $featured])) ?>">
                    <?php if ($featured): ?><span class="absolute -top-3 left-7 rounded-full bg-brand-600 px-3 py-1 text-xs font-bold text-white">Most popular</span><?php endif ?>
                    <span class="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand-700"><?= icon((string) $t['icon'], 'size-6') ?></span>
                    <h2 class="mt-5 text-xl font-bold"><?= e($t['name']) ?></h2>
                    <p class="mt-2 min-h-12 text-sm text-muted"><?= e($t['description']) ?></p>
                    <div class="mt-6 space-y-2 border-t border-line pt-6">
                        <?php foreach ($t['rates'] as $unit => $rate): ?>
                            <p class="flex items-baseline gap-1.5">
                                <span class="font-display text-3xl font-extrabold tracking-tight"><?= e(money($rate['amount'])) ?></span>
                                <span class="text-sm text-muted"><?= e(($t['code'] === 'CABIN' ? 'per cabin ' : '') . ($unitNames[$unit] ?? $unit)) ?></span>
                            </p>
                        <?php endforeach ?>
                    </div>
                    <ul class="mt-6 space-y-2.5 text-sm">
                        <li class="flex gap-2"><?= icon('check', 'size-4 mt-0.5 text-emerald-600') ?> Wi-Fi, AC, power backup, pantry</li>
                        <?php if ($t['whole_unit_only']): ?><li class="flex gap-2"><?= icon('check', 'size-4 mt-0.5 text-emerald-600') ?> Booked as a whole <?= $t['hourly_only'] ? 'room' : 'cabin' ?> (<?= (int) ($t['chairs'] / max(1, $t['units'])) ?> seats)</li><?php endif ?>
                        <?php if ($t['multi_select']): ?><li class="flex gap-2"><?= icon('check', 'size-4 mt-0.5 text-emerald-600') ?> Book several seats together</li><?php endif ?>
                        <?php if ($t['hourly_only']): ?><li class="flex gap-2"><?= icon('check', 'size-4 mt-0.5 text-emerald-600') ?> Projector available as add-on</li><?php endif ?>
                    </ul>
                    <a href="<?= e(url('spaces')) ?>#<?= e(strtolower((string) $t['short_name'])) ?>" class="<?= $featured ? 'btn btn-brand' : 'btn btn-outline' ?> mt-8 w-full">Choose <?= e(strtolower((string) $t['short_name'])) ?></a>
                </div>
            <?php endforeach ?>
        </div>

        <div class="mt-16 grid gap-6 lg:grid-cols-2">
            <div class="card card-body">
                <h2 class="text-xl font-bold">How payment works</h2>
                <ul class="mt-5 space-y-4 text-sm">
                    <li class="flex gap-4"><span aria-hidden="true" class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><?= icon('wallet', 'size-5') ?></span>
                        <div><p class="font-semibold">Up to <?= $advanceMonths ?> months — advance payment</p><p class="mt-0.5 text-muted">Pay the booking amount in advance; your seat is allocated once payment is logged.</p></div></li>
                    <li class="flex gap-4"><span aria-hidden="true" class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700"><?= icon('shield-check', 'size-5') ?></span>
                        <div><p class="font-semibold">More than <?= $advanceMonths ?> months — security deposit</p><p class="mt-0.5 text-muted">A refundable security deposit secures your tenure, followed by periodic rent invoices.</p></div></li>
                    <li class="flex gap-4"><span aria-hidden="true" class="grid size-10 shrink-0 place-items-center rounded-xl bg-accent-50 text-accent-600"><?= icon('receipt-indian-rupee', 'size-5') ?></span>
                        <div><p class="font-semibold">GST invoices for every payment</p><p class="mt-0.5 text-muted">CGST + SGST within Kerala, IGST for out-of-state GSTINs. Institutions can add their GSTIN for input credit.</p></div></li>
                </ul>
            </div>
            <div>
                <h2 class="mb-4 text-xl font-bold">Add-ons</h2>
                <?= $this->component('table', [
                    'caption' => 'Add-on facilities and prices',
                    'columns' => [
                        ['key' => 'name', 'label' => 'Facility', 'html' => true, 'render' => fn ($r) => '<span class="mr-2" aria-hidden="true">' . e($r['emoji']) . '</span><span class="font-semibold">' . e($r['name']) . '</span>'],
                        ['key' => 'unit', 'label' => 'Unit', 'render' => fn ($r) => App\Enums\FacilityUnit::tryFrom((string) $r['unit'])?->label() ?? '—'],
                        ['key' => 'price', 'label' => 'Price', 'align' => 'right', 'render' => fn ($r) => money($r['price'])],
                    ],
                    'rows' => $addons,
                    'empty' => 'No add-ons configured.',
                ]) ?>
                <p class="help mt-3">Prices exclude GST. Lockers and parking are subject to availability.</p>
            </div>
        </div>
    </div>
</section>

<?= $this->partial('site/partials/cta') ?>
