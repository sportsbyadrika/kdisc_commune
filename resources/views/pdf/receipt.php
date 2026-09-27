<?php
/**
 * Payment receipt / security deposit receipt RCPT/{FY}/{0001} — FinanceDocuments::render('receipt').
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $receipt
 * @var array<string, mixed>|null $booking
 * @var array<string, mixed> $customer
 * @var array<string, mixed> $payment
 * @var string|null $invoiceNo
 * @var string $modeLabel
 * @var array<string, string> $supplier
 * @var string $qr
 * @var bool $duplicate
 */
$deposit = $receipt['kind'] === 'deposit';
$this->layout('pdf/layout', ['title' => 'Receipt ' . $receipt['receipt_no'], 'duplicate' => $duplicate]);
$m = static fn ($v) => number_format((float) $v, 2);
?>
<?= $this->partial('pdf/partials/header', ['docTitle' => $deposit ? 'DEPOSIT RECEIPT' : 'PAYMENT RECEIPT', 'docTag' => $deposit ? 'Refundable security deposit' : 'Original']) ?>

<table class="mt16">
    <tr>
        <td style="width: 60%;">
            <p class="muted upper small">Received with thanks from</p>
            <p class="b" style="font-size: 12pt; color:#0b1b3f; margin-top: 2pt;"><?= e($receipt['customer_name'] ?? ($customer['name'] ?? '')) ?></p>
            <p class="muted"><?= e(trim(implode(', ', array_filter([(string) ($customer['address'] ?? ''), (string) ($customer['city'] ?? ''), (string) ($customer['pincode'] ?? '')])))) ?></p>
            <p class="mt4">Unique ID <b class="mono"><?= e($customer['unique_id'] ?? '—') ?></b><?php if (!empty($customer['gstin'])): ?> &nbsp;·&nbsp; GSTIN <b class="mono"><?= e($customer['gstin']) ?></b><?php endif ?></p>
        </td>
        <td style="width: 40%;">
            <div class="box">
                <div class="box-body">
                    <table class="kv">
                        <tr><td class="k">Receipt no.</td><td class="v mono" style="color:#0b1b3f;"><?= e($receipt['receipt_no']) ?></td></tr>
                        <tr><td class="k">Receipt date</td><td class="v"><?= e(format_date((string) $receipt['receipt_date'])) ?></td></tr>
                        <?php if ($booking !== null): ?><tr><td class="k">Booking no.</td><td class="v mono"><?= e($booking['booking_no']) ?></td></tr><?php endif ?>
                        <?php if (!empty($invoiceNo)): ?><tr><td class="k">Against invoice</td><td class="v mono"><?= e($invoiceNo) ?></td></tr><?php endif ?>
                    </table>
                </div>
            </div>
        </td>
    </tr>
</table>

<table class="mt16 box">
    <tr>
        <td style="padding: 12pt 14pt; width: 62%; border-right: 0.8pt solid #d1d5db;">
            <p class="muted upper small">The sum of</p>
            <p class="amount-big mt4">₹ <?= $m($receipt['amount']) ?></p>
            <p class="b mt4"><?= e($receipt['amount_words']) ?></p>
        </td>
        <td style="padding: 12pt 14pt;">
            <table class="kv">
                <tr><td class="k">Towards</td><td class="v"><?= $deposit ? 'Security deposit' : e(App\Enums\PaymentKind::tryFrom((string) ($payment['kind'] ?? ''))?->label() ?? 'Booking payment') ?></td></tr>
                <tr><td class="k">Mode</td><td class="v"><?= e($modeLabel) ?></td></tr>
                <?php if (!empty($receipt['reference_no'])): ?><tr><td class="k">Reference</td><td class="v mono"><?= e($receipt['reference_no']) ?></td></tr><?php endif ?>
                <tr><td class="k">Paid on</td><td class="v"><?= e(format_date((string) $receipt['paid_on'])) ?></td></tr>
                <tr><td class="k">Status</td><td class="v"><span class="pill pill-ok">Verified</span></td></tr>
            </table>
        </td>
    </tr>
</table>

<?php if ($booking !== null): ?>
    <div class="section-title">Booking</div>
    <table class="grid">
        <thead><tr><th>Space</th><th>Seats</th><th>Period</th><th class="r">Booking total (₹)</th><?php if ((float) $booking['deposit_amount'] > 0): ?><th class="r">Security deposit (₹)</th><?php endif ?></tr></thead>
        <tbody><tr>
            <td><?= e($booking['category_name']) ?></td>
            <td><?= e((string) $booking['seat_codes']) ?></td>
            <td class="nowrap"><?= e(format_date((string) $booking['start_date']) . ' – ' . format_date((string) $booking['end_date'])) ?><?= $booking['start_time'] ? ' · ' . e(substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)) : '' ?></td>
            <td class="r"><?= $m($booking['grand_total']) ?></td>
            <?php if ((float) $booking['deposit_amount'] > 0): ?><td class="r"><?= $m($booking['deposit_amount']) ?></td><?php endif ?>
        </tr></tbody>
    </table>
<?php endif ?>

<?php if ($deposit): ?>
    <p class="note mt12">This security deposit is held by <?= e($supplier['legal_name'] ?? 'K-DISC') ?> for the tenure of the booking. It is not a taxable supply and no GST is charged on it. It is refundable at the end of the tenure after adjustment of any dues, as recorded on a deposit refund voucher.</p>
<?php else: ?>
    <p class="note mt12">This receipt acknowledges money received. The GST tax invoice for the service is issued separately<?= !empty($invoiceNo) ? ' (' . e($invoiceNo) . ')' : '' ?>.</p>
<?php endif ?>

<table class="mt16">
    <tr>
        <td style="width: 50%;" class="qr"><img src="<?= e($qr) ?>" alt=""><div class="qr-cap">Receipt <?= e($receipt['receipt_no']) ?></div></td>
        <td style="width: 50%; vertical-align: bottom;"><?= $this->partial('pdf/partials/signature') ?></td>
    </tr>
</table>
<div class="foot"><?= e($supplier['footer'] ?? '') ?><?= !empty($payment['verified_by_name']) ? ' Verified by ' . e($payment['verified_by_name']) . '.' : '' ?></div>
