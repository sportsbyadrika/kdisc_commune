<?php
/** @var App\Core\Template $this @var array<string, int|float> $counts */
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => "Today's arrivals", 'value' => $counts['arrivals_today'], 'icon' => 'calendar-check', 'tone' => 'brand', 'hint' => 'Confirmed bookings starting today']) ?>
    <?= $this->component('stat', ['label' => 'Online requests', 'value' => $counts['bookings_requested'], 'icon' => 'inbox', 'tone' => 'warning', 'hint' => 'Awaiting approval']) ?>
    <?= $this->component('stat', ['label' => 'KYC pending', 'value' => $counts['kyc_pending'], 'icon' => 'shield-check', 'tone' => 'info', 'hint' => 'Waiting for verification', 'href' => url('staff.visitors.index', ['kyc' => 'pending'])]) ?>
    <?= $this->component('stat', ['label' => 'Registered visitors', 'value' => $counts['customers'], 'icon' => 'users', 'tone' => 'success', 'href' => url('staff.visitors.index')]) ?>
</div>
<div class="mt-6 grid gap-4 md:grid-cols-3">
    <?php foreach ([
        ['user-plus', 'Register a walk-in', 'Individual or institution, with KYC proofs', null, url('staff.visitors.create')],
        ['map', 'Book on the seat map', 'Pick seats and add-ons for a visitor', 'Batch 3', null],
        ['scan-line', 'Check-in / out', 'Mark arrivals and departures', 'Batch 4', null],
    ] as [$ic, $t, $d, $when, $href]): ?>
        <?php if ($href !== null): ?>
            <a href="<?= e($href) ?>" class="card card-hover flex items-start gap-4 p-5">
                <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-500 text-white"><?= icon($ic, 'size-5') ?></span>
                <div><p class="font-semibold"><?= e($t) ?></p><p class="text-sm text-muted"><?= e($d) ?></p><p class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-accent-600">Start <?= icon('arrow-right', 'size-3.5') ?></p></div>
            </a>
        <?php else: ?>
            <div class="card flex items-start gap-4 p-5 opacity-90">
                <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-accent-50 text-accent-600"><?= icon($ic, 'size-5') ?></span>
                <div><p class="font-semibold"><?= e($t) ?></p><p class="text-sm text-muted"><?= e($d) ?></p><p class="mt-2 text-xs font-semibold text-muted">Coming in <?= e($when) ?></p></div>
            </div>
        <?php endif ?>
    <?php endforeach ?>
</div>
