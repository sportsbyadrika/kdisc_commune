<?php
/**
 * Record the security deposit refund of an ended booking (DepositRefundService).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking
 * @var float $held
 * @var array<string, mixed> $dues
 * @var array<string, mixed>|null $existing
 * @var array<string, string> $modes
 */
$this->layout('layouts/staff', ['title' => 'Deposit refund', 'subtitle' => $booking['booking_no'] . ' · ' . $booking['customer_name'], 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Invoices & receipts', url('staff.invoices.index', ['tab' => 'deposits'])], ['Deposit refund']]]);
$ended = in_array($booking['status'], ['completed', 'cancelled'], true);
$unpaid = (float) ($dues['balance'] ?? 0);
?>
<div class="mx-auto max-w-3xl space-y-6">
    <section class="card card-body">
        <dl class="grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-xs font-semibold text-muted">Booking</dt><dd class="font-mono font-bold"><a class="text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $booking['booking_no']])) ?>"><?= e($booking['booking_no']) ?></a></dd></div>
            <div><dt class="text-xs font-semibold text-muted">Tenure</dt><dd class="font-semibold"><?= e(format_date((string) $booking['start_date'], 'd M Y') . ' – ' . format_date((string) $booking['end_date'], 'd M Y')) ?></dd></div>
            <div><dt class="text-xs font-semibold text-muted">Status</dt><dd class="font-semibold"><?= e(ucfirst((string) $booking['status'])) ?></dd></div>
            <div><dt class="text-xs font-semibold text-muted">Deposit held (verified)</dt><dd class="font-display text-xl font-extrabold tabular-nums"><?= e(money($held, 2)) ?></dd></div>
        </dl>
    </section>
    <?php if ($existing !== null): ?>
        <?= $this->component('alert', ['tone' => 'info', 'message' => 'Refund voucher ' . $existing['voucher_no'] . ' was already recorded for this booking.']) ?>
    <?php elseif (!$ended): ?>
        <?= $this->component('alert', ['tone' => 'warning', 'message' => 'Deposits are refunded once the booking has ended (completed or cancelled).']) ?>
    <?php elseif ($held <= 0): ?>
        <?= $this->component('alert', ['tone' => 'warning', 'message' => 'No verified security deposit is held for this booking.']) ?>
    <?php else: ?>
        <form method="post" action="<?= e(url('staff.deposits.store', ['no' => $booking['booking_no']])) ?>" class="card card-body space-y-5" x-data="{ rows: [{ label: '<?= $unpaid > 0 ? 'Unpaid dues on ' . e($booking['booking_no']) : '' ?>', amount: '<?= $unpaid > 0 ? number_format(min($unpaid, $held), 2, '.', '') : '' ?>' }], held: <?= json_encode($held) ?>, get adj() { return this.rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0) } }">
            <?= csrf_field() ?>
            <div>
                <h2 class="text-lg font-bold">Adjustments</h2>
                <p class="text-sm text-muted">Deductions from the deposit — unpaid dues, damages, lost locker key… Leave empty to refund in full.</p>
                <template x-for="(row, i) in rows" :key="i">
                    <div class="mt-3 flex gap-2">
                        <input name="adj_label[]" x-model="row.label" class="input flex-1" placeholder="Description" aria-label="Adjustment description">
                        <input name="adj_amount[]" x-model="row.amount" type="number" step="0.01" min="0" class="input w-36" placeholder="0.00" aria-label="Amount">
                        <button type="button" class="btn btn-ghost btn-sm" @click="rows.splice(i, 1)" aria-label="Remove"><?= icon('x', 'size-4') ?></button>
                    </div>
                </template>
                <button type="button" class="btn btn-outline btn-sm mt-3" @click="rows.push({ label: '', amount: '' })"><?= icon('plus', 'size-4') ?>Add adjustment</button>
            </div>
            <div class="rounded-2xl bg-surface p-4 text-sm">
                <div class="flex justify-between"><span class="text-muted">Deposit held</span><b class="tabular-nums"><?= e(money($held, 2)) ?></b></div>
                <div class="mt-1 flex justify-between"><span class="text-muted">Adjustments</span><b class="tabular-nums" x-text="'− ₹' + adj.toLocaleString('en-IN', { minimumFractionDigits: 2 })"></b></div>
                <div class="mt-2 flex justify-between border-t border-line pt-2 text-base"><span class="font-bold">Refund</span><b class="tabular-nums" :class="held - adj < 0 && 'text-red-700'" x-text="'₹' + Math.max(0, held - adj).toLocaleString('en-IN', { minimumFractionDigits: 2 })"></b></div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <?= $this->component('select', ['name' => 'mode', 'label' => 'Refund mode', 'options' => $modes, 'value' => 'bank_transfer']) ?>
                <?= $this->component('input', ['name' => 'reference_no', 'label' => 'Reference (UTR / cheque no.)']) ?>
            </div>
            <?= $this->component('textarea', ['name' => 'notes', 'label' => 'Notes', 'rows' => 2]) ?>
            <div class="flex justify-end"><button class="btn btn-brand"><?= icon('banknote', 'size-4') ?>Record refund voucher</button></div>
        </form>
    <?php endif ?>
</div>
