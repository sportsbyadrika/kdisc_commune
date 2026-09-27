<?php
/**
 * Public home page.
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $spaceTypes
 * @var list<array<string, mixed>> $included
 * @var list<array<string, mixed>> $addons
 * @var list<array<string, mixed>> $floors
 * @var array<string, mixed>|null $building
 * @var int $totalSeats
 */
$this->layout('layouts/site', ['hideFlash' => true]);
$minPrice = null;
foreach ($spaceTypes as $t) {
    foreach ($t['rates'] as $unit => $r) {
        if ($unit === 'day') {
            $minPrice = $minPrice === null ? $r['amount'] : min($minPrice, $r['amount']);
        }
    }
}
$steps = [
    ['user-plus', 'Register', 'Sign up online with your email — or walk in and our front desk will register you.'],
    ['shield-check', 'Verify your KYC', 'Upload Aadhaar (and PAN/GST for institutions). We verify it and issue your Commune ID.'],
    ['armchair', 'Pick your seat', 'Explore the building floor by floor, tap the seats you want and add facilities.'],
    ['credit-card', 'Pay & move in', 'Pay the advance or deposit at the centre, get your GST invoice and start working.'],
];
?>
<!-- Hero -->
<section class="relative isolate overflow-hidden bg-brand-950">
    <img src="<?= e(media($building['photo_path'] ?? null)) ?>" alt="The Commune building in Kottarakara" class="absolute inset-0 -z-20 size-full object-cover object-[70%_center]">
    <div class="hero-overlay absolute inset-0 -z-10"></div>
    <div class="absolute inset-0 -z-10 bg-brand-950/60 lg:hidden"></div>
    <div class="container-page flex min-h-[560px] flex-col justify-center pt-16 pb-40 sm:min-h-[620px] lg:pb-36">
        <p class="eyebrow !text-accent-400 animate-fade-up">K-DISC · Commune Kottarakara</p>
        <h1 class="mt-4 max-w-2xl font-display text-5xl leading-[1.02] font-extrabold !text-white sm:text-6xl lg:text-7xl animate-fade-up">
            Work near home,<br><span class="bg-gradient-to-r from-accent-400 to-brand-300 bg-clip-text text-transparent">Kottarakara.</span>
        </h1>
        <p class="mt-6 max-w-xl text-lg text-white/80 sm:text-xl animate-fade-up">Professional desks, private cabins and a conference room — minutes from home. Book by the day, month or hour.</p>
        <ul class="mt-7 flex flex-wrap gap-2 animate-fade-up">
            <?php foreach (array_slice($included, 0, 4) as $f): ?>
                <li class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-sm font-medium text-white ring-1 ring-white/20 backdrop-blur"><span aria-hidden="true"><?= e($f['emoji']) ?></span><?= e($f['name']) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
</section>

<!-- Check availability bar -->
<div class="container-page relative z-10 -mt-28 lg:-mt-20">
    <form action="<?= e(url('spaces')) ?>" method="get" x-data="availabilityBar()" class="card grid gap-4 p-4 shadow-[var(--shadow-card-hover)] sm:p-5 lg:grid-cols-[1.2fr_1fr_1fr_auto] lg:items-end lg:gap-3 lg:rounded-full lg:p-3 lg:pl-8">
        <div>
            <label for="hb-type" class="text-xs font-bold tracking-wide text-muted uppercase">Space type</label>
            <select id="hb-type" name="type" class="mt-1 w-full border-0 bg-transparent p-0 text-base font-semibold text-ink focus:ring-0 lg:py-1">
                <option value="">Any space</option>
                <?php foreach ($spaceTypes as $t): ?><option value="<?= e($t['code']) ?>"><?= e($t['name']) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="border-line lg:border-l lg:pl-6">
            <label for="hb-from" class="text-xs font-bold tracking-wide text-muted uppercase">From</label>
            <input id="hb-from" type="date" name="from" x-model="from" :min="today" @change="fromChanged()" class="mt-1 w-full border-0 bg-transparent p-0 text-base font-semibold text-ink focus:ring-0 lg:py-1">
        </div>
        <div class="border-line lg:border-l lg:pl-6">
            <label for="hb-to" class="text-xs font-bold tracking-wide text-muted uppercase">To</label>
            <input id="hb-to" type="date" name="to" x-model="to" :min="from" class="mt-1 w-full border-0 bg-transparent p-0 text-base font-semibold text-ink focus:ring-0 lg:py-1">
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full lg:w-auto"><?= icon('search', 'size-5') ?> Check availability</button>
    </form>
</div>

<!-- Quick facts -->
<section class="container-page mt-14">
    <dl class="grid grid-cols-2 gap-6 border-b border-line pb-12 lg:grid-cols-4">
        <?php foreach ([
            [$totalSeats, 'seats across the building'],
            [count($spaceTypes), 'ways to work'],
            [count($floors), 'floors, one community'],
            [$minPrice !== null ? money($minPrice) : '—', 'a day for a flexi desk'],
        ] as [$value, $label]): ?>
            <div>
                <dt class="sr-only"><?= e($label) ?></dt>
                <dd class="font-display text-4xl font-extrabold tracking-tight text-brand-900 sm:text-5xl"><?= e($value) ?></dd>
                <dd class="mt-1 text-sm font-medium text-muted"><?= e($label) ?></dd>
            </div>
        <?php endforeach ?>
    </dl>
</section>

<!-- Space types -->
<section class="section" id="spaces">
    <div class="container-page">
        <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="eyebrow">Spaces</p>
                <h2 class="section-title mt-2">Find the space that fits your day</h2>
                <p class="lead mt-3 max-w-2xl">From a drop-in hot desk to a private cabin for your team — every option comes with high-speed Wi-Fi, AC and power backup.</p>
            </div>
            <a href="<?= e(url('pricing')) ?>" class="btn btn-outline shrink-0">See all pricing <?= icon('arrow-right', 'size-4') ?></a>
        </div>
        <?= $this->partial('site/partials/space-cards') ?>
    </div>
</section>

<!-- Explore the building -->
<section class="section bg-brand-950 text-white">
    <div class="container-page grid items-center gap-12 lg:grid-cols-[1fr_1.35fr]">
        <div>
            <p class="eyebrow !text-accent-400">Space Explorer</p>
            <h2 class="section-title mt-2 !text-white">Pick your exact seat — floor by floor</h2>
            <p class="mt-4 text-lg text-white/70">Tap a floor on the building, zoom into the plan and choose the chair you like, BookMyShow-style. Facilities such as lockers and parking are added in the same step.</p>
            <ul class="mt-8 space-y-3">
                <?php foreach (array_reverse($floors) as $floor): ?>
                    <li>
                        <a href="<?= e(url('spaces')) ?>#<?= e($floor['slug']) ?>" class="group flex items-center justify-between rounded-2xl bg-white/5 px-5 py-4 ring-1 ring-white/10 transition hover:bg-white/10">
                            <span>
                                <span class="block font-semibold text-white"><?= e($floor['name']) ?></span>
                                <span class="text-sm text-white/60">
                                    <?= e(implode(' · ', array_map(static fn ($code, $n) => $n . ' ' . strtolower(App\Enums\SeatCategory::tryFrom($code)?->shortLabel() ?? $code), array_keys($floor['by_category']), $floor['by_category']))) ?>
                                </span>
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="rounded-full bg-accent-500/15 px-3 py-1 text-sm font-bold text-accent-400"><?= (int) $floor['total_seats'] ?> seats</span>
                                <?= icon('arrow-right', 'size-5 text-white/50 transition group-hover:translate-x-1 group-hover:text-white') ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
        <div class="relative overflow-hidden rounded-3xl ring-1 ring-white/10 shadow-2xl" x-data="{ hover: null }">
            <img src="<?= e(media($building['photo_path'] ?? null)) ?>" alt="Building with floor hotspots" class="w-full">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full" aria-hidden="true">
                <?php foreach ($floors as $floor):
                    $points = implode(' ', array_map(static fn ($p) => $p[0] . ',' . $p[1], $floor['hotspot'])); ?>
                    <a href="<?= e(url('spaces')) ?>#<?= e($floor['slug']) ?>">
                        <polygon points="<?= e($points) ?>" class="cursor-pointer fill-accent-500/0 stroke-white/0 transition hover:fill-accent-500/25 hover:stroke-white" stroke-width=".4" vector-effect="non-scaling-stroke"
                                 @mouseenter="hover = '<?= e($floor['slug']) ?>'" @mouseleave="hover = null"/>
                    </a>
                <?php endforeach ?>
            </svg>
            <?php foreach ($floors as $floor): $top = min(array_column($floor['hotspot'], 1)); ?>
                <span class="pointer-events-none absolute left-[16%] rounded-full bg-white px-3 py-1 text-xs font-bold text-brand-900 shadow-lg transition"
                      style="top: calc(<?= (float) $top ?>% + 8px)" :class="hover === '<?= e($floor['slug']) ?>' ? 'opacity-100 scale-100' : 'opacity-80 scale-95'">
                    <?= e($floor['name']) ?> · <?= (int) $floor['total_seats'] ?> seats
                </span>
            <?php endforeach ?>
        </div>
    </div>
</section>

<!-- Facilities -->
<section class="section bg-surface" id="facilities">
    <div class="container-page">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow">Facilities</p>
            <h2 class="section-title mt-2">Everything you need to do your best work</h2>
            <p class="lead mt-3">Included with every booking, with optional add-ons you can pick right on the seat map.</p>
        </div>
        <div class="mt-12 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <?php foreach ($included as $f): ?>
                <div class="card flex flex-col items-center p-6 text-center">
                    <span class="grid size-16 place-items-center rounded-2xl bg-brand-50 text-3xl" aria-hidden="true"><?= e($f['emoji']) ?></span>
                    <p class="mt-4 font-semibold"><?= e($f['name']) ?></p>
                    <p class="mt-1 text-xs font-semibold text-emerald-700">Included</p>
                </div>
            <?php endforeach ?>
        </div>
        <h3 class="mt-14 mb-5 text-xl font-bold">Popular add-ons</h3>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($addons as $f): ?>
                <?= $this->component('facility-tile', ['facility' => $f, 'compact' => true]) ?>
            <?php endforeach ?>
        </div>
    </div>
</section>

<!-- How it works -->
<section class="section">
    <div class="container-page">
        <div class="max-w-2xl">
            <p class="eyebrow">How it works</p>
            <h2 class="section-title mt-2">From sign-up to your desk in four steps</h2>
        </div>
        <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($steps as $i => [$ic, $heading, $text]): ?>
                <li class="relative rounded-3xl border border-line p-6">
                    <span class="font-display text-6xl font-extrabold text-surface-2 absolute top-4 right-5 select-none" aria-hidden="true"><?= $i + 1 ?></span>
                    <span class="grid size-12 place-items-center rounded-2xl bg-accent-500 text-white shadow-lg shadow-accent-500/30"><?= icon($ic, 'size-6') ?></span>
                    <h3 class="mt-5 text-lg font-bold"><?= e($heading) ?></h3>
                    <p class="mt-2 text-sm leading-6 text-muted"><?= e($text) ?></p>
                </li>
            <?php endforeach ?>
        </ol>
    </div>
</section>

<?= $this->partial('site/partials/cta') ?>
