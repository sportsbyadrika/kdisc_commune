<?php
/** @var App\Core\Template $this @var array<string, int|float> $counts */
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => 'Payments to verify', 'value' => $counts['payments_pending'], 'icon' => 'wallet', 'tone' => 'warning', 'hint' => money($counts['payments_pending_amount']) . ' pending']) ?>
    <?= $this->component('stat', ['label' => 'Collected this month', 'value' => money($counts['collected_this_month']), 'icon' => 'badge-indian-rupee', 'tone' => 'success']) ?>
    <?= $this->component('stat', ['label' => 'Invoices this month', 'value' => $counts['invoices_this_month'], 'icon' => 'receipt-indian-rupee', 'tone' => 'brand']) ?>
    <?= $this->component('stat', ['label' => 'GST rate', 'value' => rtrim(rtrim(number_format((float) setting('gst_rate', 18), 2), '0'), '.') . '%', 'icon' => 'landmark', 'tone' => 'info', 'hint' => 'SAC ' . setting('sac_code', '—') . ' · prefix ' . setting('invoice_prefix', '')]) ?>
</div>
<div class="mt-6">
    <?= $this->component('empty', ['icon' => 'receipt', 'title' => 'No payments logged yet', 'text' => 'Verified payments, GST invoices and receipts will appear here once bookings start.']) ?>
</div>
