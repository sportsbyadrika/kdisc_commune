<?php
/**
 * Media card: image, category pill, price badge, facility icon row, arrow CTA (spec §11).
 * Used for space types; also suitable for bookings.
 *
 *   <?= $this->component('card', [
 *       'href' => url('spaces'), 'image' => 'media/space-flexi.svg', 'title' => 'Flexi desk',
 *       'category' => 'Flexi', 'tone' => 'success', 'price' => money(4000), 'priceUnit' => '/month',
 *       'text' => '...', 'meta' => '42 seats · 2 floors',
 *       'facilities' => [['icon' => 'wifi', 'label' => 'Wi-Fi'], ...], 'cta' => 'View seats']) ?>
 *
 * @var string $title
 * @var string|null $href
 * @var string|null $image  media path or URL
 * @var string|null $category
 * @var string|null $tone
 * @var string|null $price
 * @var string|null $priceUnit
 * @var string|null $text
 * @var string|null $meta
 * @var list<array{icon: string, label: string}>|null $facilities
 * @var string|null $cta
 * @var array<string, scalar|null>|null $attrs
 */
$tag = !empty($href) ? 'a' : 'article';
?>
<<?= $tag ?> <?= !empty($href) ? 'href="' . e($href) . '"' : '' ?> class="group card card-hover flex flex-col overflow-hidden"<?= attrs($attrs ?? []) ?>>
    <div class="relative aspect-[16/10] overflow-hidden bg-surface-2">
        <img src="<?= e(media($image ?? null)) ?>" alt="" loading="lazy" class="size-full object-cover transition duration-500 group-hover:scale-105">
        <?php if (!empty($category)): ?>
            <span class="absolute top-4 left-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold tracking-wide text-ink uppercase shadow-sm backdrop-blur"><?= e($category) ?></span>
        <?php endif ?>
        <?php if (!empty($price)): ?>
            <span class="absolute right-4 bottom-4 rounded-2xl bg-brand-900/90 px-3.5 py-2 text-white shadow-lg backdrop-blur">
                <span class="block text-[10px] font-semibold tracking-wider text-white/60 uppercase">From</span>
                <span class="font-display text-lg leading-none font-extrabold"><?= e($price) ?></span><span class="text-xs text-white/70"><?= e($priceUnit ?? '') ?></span>
            </span>
        <?php endif ?>
    </div>
    <div class="flex flex-1 flex-col p-5 sm:p-6">
        <?php if (!empty($meta)): ?><p class="text-xs font-semibold tracking-wide text-accent-500 uppercase"><?= e($meta) ?></p><?php endif ?>
        <h3 class="mt-1.5 text-xl font-bold"><?= e($title) ?></h3>
        <?php if (!empty($text)): ?><p class="mt-2 line-clamp-3 text-sm leading-6 text-muted"><?= e($text) ?></p><?php endif ?>
        <div class="mt-auto flex items-center justify-between gap-3 pt-5">
            <div class="flex items-center gap-1.5">
                <?php foreach (array_slice($facilities ?? [], 0, 5) as $f): ?>
                    <span class="grid size-8 place-items-center rounded-full bg-surface text-ink/70" title="<?= e($f['label']) ?>"><?= icon($f['icon'], 'size-4', $f['label']) ?></span>
                <?php endforeach ?>
            </div>
            <span class="inline-flex items-center gap-1.5 text-sm font-bold text-ink">
                <span class="hidden whitespace-nowrap sm:inline xl:hidden"><?= e($cta ?? 'Explore') ?></span>
                <span class="grid size-10 place-items-center rounded-full bg-accent-500 text-white transition duration-300 group-hover:translate-x-0.5 group-hover:bg-accent-600"><?= icon('arrow-right', 'size-[18px]') ?></span>
            </span>
        </div>
    </div>
</<?= $tag ?>>
