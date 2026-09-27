<?php
/** @var App\Core\Template $this @var array<string, int|float> $counts @var int $totalSeats */
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => 'Requests to approve', 'value' => $counts['bookings_requested'], 'icon' => 'inbox', 'tone' => 'warning']) ?>
    <?= $this->component('stat', ['label' => 'KYC to verify', 'value' => $counts['kyc_pending'], 'icon' => 'shield-check', 'tone' => 'info', 'href' => url('staff.kyc.index')]) ?>
    <?= $this->component('stat', ['label' => 'Active bookings', 'value' => $counts['bookings_active'], 'icon' => 'calendar-check', 'tone' => 'success']) ?>
    <?= $this->component('stat', ['label' => 'Seats in inventory', 'value' => $totalSeats, 'icon' => 'armchair', 'tone' => 'brand', 'hint' => $counts['facilities'] . ' facilities configured']) ?>
</div>
<?= $this->component('alert', ['tone' => 'info', 'class' => 'mt-6', 'title' => 'Layout & Pricing Designer', 'message' => 'Seats, zones and default rates (effective 1 Jan 2026) are seeded. The visual designer to move seats, set prices and place facilities arrives in a later release.']) ?>
