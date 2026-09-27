<?php
/**
 * Dark multi-column footer + bottom bar (spec §11).
 *
 * @var App\Core\Template $this
 */
$org = (array) config('app.org');
$cols = [
    'Spaces' => [['Flexi / Hot desks', '/spaces#flexi'], ['Dedicated seats', '/spaces#dedicated'], ['Executive cabins', '/spaces#cabin'], ['Conference room', '/spaces#conference'], ['Pricing', '/pricing']],
    'Facilities' => [['Included facilities', '/facilities#included'], ['Add-ons', '/facilities#addons'], ['Around the building', '/facilities#landmarks']],
    'Help' => [['About Commune', '/about'], ['Contact us', '/contact'], ['Visitor sign in', '/login'], ['Create an account', '/register'], ['Staff console', '/staff/login']],
];
$socials = [['facebook', 'Facebook'], ['instagram', 'Instagram'], ['linkedin', 'LinkedIn'], ['youtube', 'YouTube']];
?>
<footer class="mt-auto bg-brand-950 text-white/70">
    <div class="container-page grid gap-12 py-16 sm:grid-cols-2 lg:grid-cols-12">
        <div class="lg:col-span-3">
            <?= $this->partial('partials/logo', ['inverse' => true]) ?>
            <p class="mt-5 max-w-sm text-sm leading-6">Commune is a “Work Near Home” initiative of the Kerala Development and Innovation Strategic Council (K-DISC) — professional workspaces close to where you live.</p>
            <div class="mt-6 flex gap-2">
                <?php foreach ($socials as [$ic, $label]): ?>
                    <a href="#" class="grid size-10 place-items-center rounded-full bg-white/8 text-white/80 ring-1 ring-white/10 transition hover:bg-accent-500 hover:text-white" aria-label="<?= e($label) ?>"><?= icon($ic, 'size-[18px]') ?></a>
                <?php endforeach ?>
            </div>
        </div>
        <?php foreach ($cols as $heading => $links): ?>
            <div class="lg:col-span-2">
                <h3 class="text-sm font-bold tracking-wide !text-white uppercase"><?= e($heading) ?></h3>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <?php foreach ($links as [$label, $path]): ?>
                        <li><a href="<?= e(url($path)) ?>" class="transition hover:text-white"><?= e($label) ?></a></li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endforeach ?>
        <div class="sm:col-span-2 lg:col-span-3">
            <h3 class="text-sm font-bold tracking-wide !text-white uppercase">Visit us</h3>
            <ul class="mt-4 space-y-3 text-sm">
                <li class="flex gap-2.5"><?= icon('map-pin', 'mt-0.5 size-4 shrink-0 text-accent-400') ?><span><?= e($org['address'] ?? '') ?></span></li>
                <li class="flex gap-2.5"><?= icon('phone', 'mt-0.5 size-4 shrink-0 text-accent-400') ?><span><?= e($org['phone'] ?? '') ?></span></li>
                <li class="flex gap-2.5"><?= icon('mail', 'mt-0.5 size-4 shrink-0 text-accent-400') ?><span class="break-all"><?= e($org['email'] ?? '') ?></span></li>
                <li class="flex gap-2.5"><?= icon('clock', 'mt-0.5 size-4 shrink-0 text-accent-400') ?><span><?= e($org['hours'] ?? '') ?></span></li>
            </ul>
            <a href="<?= e($org['map_url'] ?? '#') ?>" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-white hover:text-accent-400">Open in Maps <?= icon('arrow-up-right', 'size-4') ?></a>
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="container-page flex flex-col items-center justify-between gap-3 py-6 text-xs sm:flex-row">
            <p>&copy; <?= date('Y') ?> K-DISC, Government of Kerala. All rights reserved.</p>
            <nav class="flex gap-5" aria-label="Legal">
                <a href="<?= e(url('privacy')) ?>" class="hover:text-white">Privacy policy</a>
                <a href="<?= e(url('terms')) ?>" class="hover:text-white">Terms of use</a>
                <a href="<?= e(url('contact')) ?>" class="hover:text-white">Contact</a>
            </nav>
        </div>
    </div>
</footer>
