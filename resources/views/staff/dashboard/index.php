<?php
/**
 * Staff dashboard shell; the role-specific body lives in staff/dashboard/{role}.php.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $user
 * @var App\Enums\StaffRole $role
 * @var array<string, int|float> $counts
 * @var list<array<string, mixed>> $spaceTypes
 * @var list<array<string, mixed>> $floors
 * @var int $totalSeats
 * @var list<array<string, mixed>> $recentLogins
 * @var array<string, mixed>|null $desk     front-desk board (receptionist / Centre Manager)
 * @var list<array<string, mixed>> $occupancy
 */
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$this->layout('layouts/staff', [
    'title' => $greeting . ', ' . explode(' ', (string) $user['name'])[0],
    'subtitle' => $role->label() . ' · ' . $role->description(),
]);
?>
<?php if (!empty($occupancy)): ?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/js/space-render.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/frontdesk.js')) ?>"></script>
<?php $this->stop() ?>
<?php endif ?>
<?php $this->start('actions') ?>
    <?= $this->component('badge', ['label' => date('l, j F Y'), 'tone' => 'neutral', 'icon' => 'calendar']) ?>
<?php $this->stop() ?>

<?php if (!empty($occupancy)): ?><?= $this->partial('partials/space/sprite', ['extra' => []]) ?><?php endif ?>
<?= $this->partial($role->dashboardView()) ?>

<div class="mt-8 grid gap-6 xl:grid-cols-3">
    <section class="card card-body xl:col-span-2">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold">Inventory by space type</h2>
            <?= $this->component('badge', ['label' => $totalSeats . ' seats', 'tone' => 'brand']) ?>
        </div>
        <div class="mt-5 space-y-4">
            <?php foreach ($spaceTypes as $t):
                $pct = $totalSeats > 0 ? round($t['chairs'] / $totalSeats * 100) : 0; ?>
                <div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="inline-flex items-center gap-2 font-semibold"><span class="size-2.5 rounded-full" style="background: <?= e($t['colour']) ?>"></span><?= e($t['name']) ?></span>
                        <span class="text-muted"><?= (int) $t['chairs'] ?> seats<?= $t['whole_unit_only'] ? ' · ' . (int) $t['units'] . ' ' . ($t['hourly_only'] ? 'room' : 'cabins') : '' ?> · <strong class="text-ink"><?= $t['headline_amount'] !== null ? e(money($t['headline_amount'])) . '/' . e($t['headline_unit']) : '—' ?></strong></span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full" style="width: <?= (int) $pct ?>%; background: <?= e($t['colour']) ?>"></div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <?php foreach ($floors as $floor): ?>
                <div class="flex items-center justify-between rounded-2xl bg-surface px-4 py-3">
                    <span class="inline-flex items-center gap-2 text-sm font-semibold"><?= icon('layers', 'size-4 text-brand-600') ?><?= e($floor['name']) ?></span>
                    <span class="text-sm text-muted"><?= (int) $floor['total_seats'] ?> seats</span>
                </div>
            <?php endforeach ?>
        </div>
    </section>

    <section class="card card-body">
        <h2 class="text-lg font-bold">Recent staff sign-ins</h2>
        <ul class="mt-4 divide-y divide-line">
            <?php foreach ($recentLogins as $login): $r = App\Enums\StaffRole::tryFrom((string) $login['role']); ?>
                <li class="flex items-center justify-between gap-3 py-3 text-sm">
                    <span class="min-w-0"><span class="block truncate font-semibold"><?= e($login['name']) ?></span><span class="text-xs text-muted"><?= e(format_date((string) $login['created_at'], 'd M, h:i a')) ?></span></span>
                    <?= $this->component('badge', ['label' => $r?->label() ?? '', 'tone' => $r?->tone() ?? 'neutral']) ?>
                </li>
            <?php endforeach ?>
        </ul>
        <?php if ($recentLogins === []): ?><p class="mt-4 text-sm text-muted">No sign-ins recorded yet.</p><?php endif ?>
    </section>
</div>
