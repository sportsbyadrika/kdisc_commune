<?php
/**
 * Finance documents hub: invoice queue (verified, not yet invoiced) + issued invoices, receipts, credit notes and
 * deposit refunds per financial year.
 *
 * @var App\Core\Template $this
 * @var string $tab
 * @var string $fy
 * @var list<string> $fys
 * @var string $q
 * @var list<array<string, mixed>> $rows
 * @var int $queueCount
 * @var list<array<string, mixed>> $eligibleRefunds
 * @var int $pendingPayments
 * @var bool $canManage
 * @var bool $canRefund
 */
use App\Controllers\Staff\Finance\InvoiceController;
use App\Enums\CreditNoteReason;
use App\Enums\PaymentMode;
use App\Services\Finance\FinanceDocuments;

$this->layout('layouts/staff', ['title' => 'Invoices & receipts', 'subtitle' => 'GST tax invoices, receipts, credit notes and deposit refunds — numbered per financial year.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Finance'], ['Invoices & receipts']]]);
$pdf = static fn (string $type, array $row) => FinanceDocuments::url($type, $row);
$docLinks = function (string $type, array $row) use ($pdf, $canManage): string {
    $out = '<div class="flex justify-end gap-1.5"><a class="btn btn-outline btn-sm" href="' . e($pdf($type, $row)) . '" target="_blank" rel="noopener" title="Download PDF" aria-label="Download PDF">' . icon('file-down', 'size-4') . '</a>';
    if ($canManage) {
        $out .= '<form method="post" target="_blank" action="' . e(url('staff.finance.documents.reprint', ['type' => FinanceDocuments::urlType($type), 'id' => $row['id']])) . '">' . csrf_field()
            . '<button class="btn btn-ghost btn-sm" title="Reprint (DUPLICATE COPY watermark)" aria-label="Reprint">' . icon('printer', 'size-4') . '</button></form>';
    }
    return $out . '</div>';
};
?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.payments.index')) ?>" class="btn btn-outline"><?= icon('wallet', 'size-4') ?><span class="hidden sm:inline">Verify payments</span><?php if ($pendingPayments > 0): ?><span class="rounded-full bg-accent-500 px-1.5 text-xs text-white"><?= $pendingPayments ?></span><?php endif ?></a>
<a href="<?= e(url('staff.registers.index')) ?>" class="btn btn-outline"><?= icon('book-open-text', 'size-4') ?><span class="hidden sm:inline">Registers</span></a>
<?php $this->stop() ?>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Documents">
        <div class="flex w-max gap-2 pb-1">
            <?php foreach (InvoiceController::TABS as $key => [$label, $ic]): $on = $tab === $key; ?>
                <a href="<?= e(url('staff.invoices.index', ['tab' => $key] + ($key !== 'queue' ? ['fy' => $fy] : []))) ?>" class="chip <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>>
                    <?= icon($ic, 'size-4') ?><?= e($label) ?><?php if ($key === 'queue'): ?><span class="rounded-full px-1.5 text-xs font-bold <?= $on ? 'bg-white/20' : ($queueCount > 0 ? 'bg-accent-500 text-white' : 'bg-surface-2 text-muted') ?>"><?= $queueCount ?></span><?php endif ?>
                </a>
            <?php endforeach ?>
        </div>
    </nav>
    <?php if ($tab !== 'queue'): ?>
        <form method="get" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <label class="flex items-center gap-2 rounded-2xl bg-white px-3 ring-1 ring-line focus-within:ring-2 focus-within:ring-brand-600/30">
                <?= icon('search', 'size-4 text-muted') ?><span class="sr-only">Search</span>
                <input name="q" value="<?= e($q) ?>" class="w-44 border-0 bg-transparent py-2 text-sm focus:ring-0 focus:outline-none sm:w-60" placeholder="Number, visitor, booking">
            </label>
            <select name="fy" class="input !w-auto !py-2 text-sm" aria-label="Financial year">
                <?php foreach ($fys as $y): ?><option value="<?= e($y) ?>" <?= $y === $fy ? 'selected' : '' ?>>FY <?= e($y) ?></option><?php endforeach ?>
            </select>
            <button class="btn btn-brand btn-sm">Go</button>
        </form>
    <?php endif ?>
</div>

<?php if ($tab === 'queue'): ?>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl text-sm text-muted">Verified payments that are ready for a GST invoice: <b class="text-ink">advance bookings</b> (≤ 6 months) get one invoice once the full amount is verified; <b class="text-ink">security-deposit bookings</b> get one invoice per rent period once that period is paid. Deposits are never invoiced — they get a receipt.</p>
        <?php if ($canManage && $rows !== []): ?>
            <form method="post" action="<?= e(url('staff.invoices.store')) ?>"><?= csrf_field() ?><input type="hidden" name="all" value="1"><button class="btn btn-brand"><?= icon('receipt-indian-rupee', 'size-4') ?>Issue all <?= count($rows) ?></button></form>
        <?php endif ?>
    </div>
    <?php if ($rows === []): ?>
        <?= $this->component('empty', ['icon' => 'inbox', 'title' => 'Invoice queue is empty', 'text' => $pendingPayments > 0 ? $pendingPayments . ' logged payment(s) still need verification before they can be invoiced.' : 'Every verified payment has its invoice.', 'action' => $pendingPayments > 0 ? ['label' => 'Verify payments', 'href' => url('staff.payments.index'), 'variant' => 'brand'] : null]) ?>
    <?php else: ?>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Booking · visitor</th><th>What</th><th>Period · tax</th><th class="text-right">Taxable</th><th class="text-right">Total</th><th>Verified</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $c): ?>
                    <tr>
                        <td><a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $c['booking_no']])) ?>"><?= e($c['booking_no']) ?></a>
                            <div class="max-w-56 truncate text-sm font-semibold"><?= e($c['customer_name']) ?></div><div class="text-xs text-muted"><?= e($c['category_name']) ?></div></td>
                        <td><?= $this->component('badge', ['label' => $c['kind'] === 'rent' ? 'Rent' : 'Advance', 'tone' => $c['kind'] === 'rent' ? 'info' : 'brand']) ?><div class="mt-1 text-xs text-muted"><?= e($c['label']) ?></div></td>
                        <td class="text-sm"><?= e(format_date($c['period_start'], 'd M') . ' – ' . format_date($c['period_end'], 'd M Y')) ?><div class="mt-1"><?= $this->component('badge', ['label' => $c['inter'] ? 'IGST' : 'CGST + SGST', 'tone' => $c['inter'] ? 'warning' : 'neutral']) ?></div></td>
                        <td class="text-right tabular-nums"><?= e(money($c['taxable'], 2)) ?></td>
                        <td class="text-right font-bold tabular-nums"><?= e(money($c['amount'], 2)) ?></td>
                        <td class="text-sm text-muted"><?= e(format_date($c['verified_at'], 'd M')) ?></td>
                        <td class="text-right"><?php if ($canManage): ?>
                            <form method="post" action="<?= e(url('staff.invoices.store')) ?>"><?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int) $c['booking_id'] ?>"><input type="hidden" name="source_key" value="<?= e($c['source_key']) ?>"><button class="btn btn-brand btn-sm"><?= icon('receipt-indian-rupee', 'size-4') ?>Issue</button></form>
                        <?php endif ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

<?php elseif ($tab === 'invoices'): ?>
    <?php if ($rows === []): ?>
        <?= $this->component('empty', ['icon' => 'receipt-indian-rupee', 'title' => 'No invoices in FY ' . $fy, 'text' => $q !== '' ? 'Nothing matches your search.' : 'Issue invoices from the queue.']) ?>
    <?php else: ?>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Invoice</th><th>Recipient</th><th class="text-right">Taxable</th><th class="text-right">GST</th><th class="text-right">Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $inv): $cancelled = $inv['status'] === 'cancelled'; ?>
                    <tr>
                        <td><a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.invoices.show', ['id' => $inv['id']])) ?>"><?= e($inv['invoice_no']) ?></a><div class="text-xs text-muted"><?= e(format_date($inv['invoice_date'])) ?> · <?= $inv['kind'] === 'rent' ? 'rent' : 'advance' ?> · <a class="font-mono hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $inv['booking_no']])) ?>"><?= e($inv['booking_no']) ?></a></div></td>
                        <td><div class="max-w-56 truncate font-semibold"><?= e($inv['customer_name']) ?></div><div class="font-mono text-xs text-muted"><?= e($inv['customer_gstin'] ?: 'Unregistered') ?> · POS <?= e($inv['place_of_supply']) ?></div></td>
                        <td class="text-right tabular-nums"><?= e(money($inv['taxable_value'], 2)) ?></td>
                        <td class="text-right tabular-nums"><?= e(money((float) $inv['cgst'] + (float) $inv['sgst'] + (float) $inv['igst'], 2)) ?><div class="text-xs text-muted"><?= (float) $inv['igst'] > 0 ? 'IGST' : 'CGST+SGST' ?></div></td>
                        <td class="text-right font-bold tabular-nums"><?= e(money($inv['total'], 2)) ?></td>
                        <td><?= $this->component('badge', ['label' => $cancelled ? 'Credited in full' : ((float) $inv['credited_total'] > 0 ? 'Part credited' : 'Issued'), 'tone' => $cancelled ? 'danger' : ((float) $inv['credited_total'] > 0 ? 'warning' : 'success'), 'dot' => true]) ?></td>
                        <td><?= $docLinks('invoice', $inv) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

<?php elseif ($tab === 'receipts'): ?>
    <?php if ($rows === []): ?>
        <?= $this->component('empty', ['icon' => 'receipt', 'title' => 'No receipts in FY ' . $fy, 'text' => 'A receipt is issued automatically when Finance verifies a payment.']) ?>
    <?php else: ?>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Receipt</th><th>Received from</th><th>Booking</th><th>For</th><th>Mode · reference</th><th class="text-right">Amount</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="font-mono font-bold"><?= e($r['receipt_no']) ?></span><div class="text-xs text-muted"><?= e(format_date($r['receipt_date'])) ?> · paid <?= e(format_date($r['paid_on'], 'd M')) ?></div></td>
                        <td class="max-w-56 truncate font-semibold"><?= e($r['customer_name']) ?></td>
                        <td><a class="font-mono text-sm text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $r['booking_no']])) ?>"><?= e($r['booking_no']) ?></a></td>
                        <td><?= $this->component('badge', ['label' => $r['kind'] === 'deposit' ? 'Security deposit' : 'Payment', 'tone' => $r['kind'] === 'deposit' ? 'info' : 'brand']) ?></td>
                        <td><div class="text-sm font-semibold"><?= e(PaymentMode::tryFrom((string) $r['mode'])?->label() ?? '') ?></div><div class="font-mono text-xs text-muted"><?= e($r['reference_no'] ?? '—') ?></div></td>
                        <td class="text-right font-bold tabular-nums"><?= e(money($r['amount'], 2)) ?></td>
                        <td><?= $docLinks('receipt', $r) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

<?php elseif ($tab === 'credit-notes'): ?>
    <?php if ($rows === []): ?>
        <?= $this->component('empty', ['icon' => 'file-minus', 'title' => 'No credit notes in FY ' . $fy, 'text' => 'Open an invoice to issue a credit note (cancellation, early exit, handover difference, discount).']) ?>
    <?php else: ?>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Credit note</th><th>Against invoice</th><th>Recipient</th><th>Reason</th><th class="text-right">Taxable</th><th class="text-right">Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $cn): $reason = CreditNoteReason::tryFrom((string) $cn['reason_code']); ?>
                    <tr>
                        <td><span class="font-mono font-bold"><?= e($cn['credit_note_no']) ?></span><div class="text-xs text-muted"><?= e(format_date($cn['note_date'])) ?></div></td>
                        <td><a class="font-mono text-sm text-brand-700 hover:underline" href="<?= e(url('staff.invoices.show', ['id' => $cn['invoice_id']])) ?>"><?= e($cn['invoice_no']) ?></a></td>
                        <td class="max-w-48 truncate font-semibold"><?= e($cn['customer_name']) ?></td>
                        <td class="max-w-64 whitespace-normal"><?= $this->component('badge', ['label' => $reason?->label() ?? 'Other', 'tone' => $reason?->tone() ?? 'neutral']) ?><div class="mt-1 text-xs text-muted"><?= e($cn['reason']) ?></div></td>
                        <td class="text-right tabular-nums"><?= e(money($cn['taxable_value'], 2)) ?></td>
                        <td class="text-right font-bold tabular-nums"><?= e(money($cn['total'], 2)) ?></td>
                        <td><?= $docLinks('credit_note', $cn) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>

<?php else: ?>
    <?php if ($eligibleRefunds !== []): ?>
        <section class="card mb-5 overflow-x-auto">
            <h2 class="px-5 pt-5 font-bold">Ready for refund</h2>
            <p class="px-5 text-sm text-muted">Ended bookings still holding a verified security deposit.</p>
            <table class="table mt-3">
                <thead><tr><th>Booking</th><th>Visitor</th><th>Ended</th><th class="text-right">Deposit held</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($eligibleRefunds as $b): ?>
                    <tr>
                        <td><a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $b['booking_no']])) ?>"><?= e($b['booking_no']) ?></a></td>
                        <td class="font-semibold"><?= e($b['customer_name']) ?></td>
                        <td><?= e(format_date($b['end_date'])) ?> · <?= e(ucfirst((string) $b['status'])) ?></td>
                        <td class="text-right font-bold tabular-nums"><?= e(money($b['held'], 2)) ?></td>
                        <td class="text-right"><?php if ($canRefund): ?><a class="btn btn-brand btn-sm" href="<?= e(url('staff.deposits.create', ['no' => $b['booking_no']])) ?>"><?= icon('banknote', 'size-4') ?>Record refund</a><?php endif ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </section>
    <?php endif ?>
    <?php if ($rows === []): ?>
        <?= $this->component('empty', ['icon' => 'piggy-bank', 'title' => 'No deposit refunds in FY ' . $fy, 'text' => 'Refund vouchers are recorded when a security-deposit booking ends.']) ?>
    <?php else: ?>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Voucher</th><th>Visitor</th><th>Booking</th><th class="text-right">Held</th><th class="text-right">Adjusted</th><th class="text-right">Refunded</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $d): ?>
                    <tr>
                        <td><span class="font-mono font-bold"><?= e($d['voucher_no']) ?></span><div class="text-xs text-muted"><?= e(format_date($d['voucher_date'])) ?></div></td>
                        <td class="font-semibold"><?= e($d['customer_name']) ?></td>
                        <td><a class="font-mono text-sm text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $d['booking_no']])) ?>"><?= e($d['booking_no']) ?></a></td>
                        <td class="text-right tabular-nums"><?= e(money($d['deposit_held'], 2)) ?></td>
                        <td class="text-right tabular-nums"><?= e(money($d['adjustments_total'], 2)) ?></td>
                        <td class="text-right font-bold tabular-nums"><?= e(money($d['refund_amount'], 2)) ?></td>
                        <td><?= $docLinks('deposit_refund', $d) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
<?php endif ?>
