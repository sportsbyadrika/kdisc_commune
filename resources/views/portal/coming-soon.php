<?php
/**
 * Placeholder for visitor auth pages (batch 2 replaces these).
 *
 * @var App\Core\Template $this
 * @var string $heading
 * @var string $lead
 */
$this->layout('layouts/auth', [
    'panelTitle' => 'Your seat, near home.',
    'panelText' => 'Register once, verify your KYC and book seats online — or at the front desk.',
]);
?>
<div class="card card-body sm:!p-8">
    <?= $this->component('badge', ['label' => 'Coming soon', 'tone' => 'accent', 'icon' => 'sparkles']) ?>
    <h1 class="mt-4 text-3xl font-extrabold"><?= e($heading) ?></h1>
    <p class="mt-3 text-muted"><?= e($lead) ?></p>
    <div class="mt-8 grid gap-3">
        <a href="<?= e(url('contact')) ?>" class="btn btn-primary btn-lg w-full">Contact the front desk <?= icon('arrow-right', 'size-4') ?></a>
        <a href="<?= e(url('home')) ?>" class="btn btn-outline w-full"><?= icon('arrow-left', 'size-4') ?> Back to home</a>
    </div>
    <p class="mt-6 text-center text-xs text-muted">Staff member? <a href="<?= e(url('staff.login')) ?>" class="font-semibold text-brand-600 hover:underline">Sign in to the staff console</a></p>
</div>
