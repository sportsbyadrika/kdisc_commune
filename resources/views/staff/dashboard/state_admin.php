<?php
/** @var App\Core\Template $this @var array<string, int|float> $counts @var int $totalSeats */
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => 'Centres', 'value' => $counts['centres'], 'icon' => 'building-2', 'tone' => 'brand']) ?>
    <?= $this->component('stat', ['label' => 'Total seats', 'value' => $totalSeats, 'icon' => 'armchair', 'tone' => 'accent']) ?>
    <?= $this->component('stat', ['label' => 'Active bookings', 'value' => $counts['bookings_active'], 'icon' => 'calendar-check', 'tone' => 'success']) ?>
    <?= $this->component('stat', ['label' => 'Collected this month', 'value' => money($counts['collected_this_month']), 'icon' => 'trending-up', 'tone' => 'info']) ?>
</div>
<?= $this->component('alert', ['tone' => 'info', 'class' => 'mt-6', 'message' => 'Read-only access. Occupancy heat-maps, revenue trends and exportable reports arrive with the dashboards release.']) ?>
