<?php
/**
 * Security deposit refund voucher DRV/{FY}/{0001} — FinanceDocuments::render('deposit_refund').
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $voucher
 * @var array<string, mixed>|null $booking
 * @var array<string, mixed> $customer
 * @var list<array{label: string, amount: float}> $adjustments
 * @var list<array<string, mixed>> $deposits
 * @var string|null $modeLabel
 * @var array<string, string> $supplier
 * @var bool $duplicate
 */
$this->layout('pdf/layout', ['title' => 'Deposit refund ' . $voucher['voucher_no'], 'duplicate' => $duplicate]);
$m = static fn ($v) => number_format((float) $v, 2);
?>
<?= $this->partial('pdf/partials/header', ['docTitle' => 'DEPOSIT REFUND', 'docTag' => 'Refund voucher']) ?>

<table class="mt16">
    <tr>
        <td style="width: 60%;">
            <p class="muted upper small">Refund to</p>
            <p class="b" style="font-size: 12pt; color:#0b1b3f; margin-top: 2pt;"><?= e($voucher['customer_name']) ?></p>
            <p class="muted"><?= e(trim(implode(', ', array_filter([(string) ($customer['address'] ?? ''), (string) ($customer['city'] ?? ''), (string) ($customer['pincode'] ?? '')])))) ?></p>
            <p class="mt4">Unique ID <b class="mono"><?= e($customer['unique_id'] ?? '—') ?></b></p>
        </td>
        <td style="width: 40%;">
            <div class="box"><div class="box-body">
                <table class="kv">
                    <tr><td class="k">Voucher no.</td><td class="v mono" style="color:#0b1b3f;"><?= e($voucher['voucher_no']) ?></td></tr>
                    <tr><td class="k">Date</td><td class="v"><?= e(format_date((string) $voucher['voucher_date'])) ?></td></tr>
                    <?php if ($booking !== null): ?>
                        <tr><td class="k">Booking no.</td><td class="v mono"><?= e($booking['booking_no']) ?></td></tr>
                        <tr><td class="k">Tenure</td><td class="v"><?= e(format_date((string) $booking['start_date']) . ' – ' . format_date((string) $booking['end_date'])) ?></td></tr>
                    <?php endif ?>
                </table>
            </div></div>
        </td>
    </tr>
</table>

<div class="section-title">Deposit received</div>
<table class="grid">
    <thead><tr><th>Receipt</th><th>Paid on</th><th>Mode / reference</th><th class="r">Amount (₹)</th></tr></thead>
    <tbody>
    <?php foreach ($deposits as $d): ?>
        <tr><td class="mono"><?= e($d['receipt_no'] ?? '—') ?></td><td><?= e(format_date((string) $d['paid_on'])) ?></td><td><?= e(strtoupper((string) $d['mode'])) ?><?= $d['reference_no'] ? ' · ' . e($d['reference_no']) : '' ?></td><td class="r"><?= $m($d['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
</table>

<div class="section-title">Settlement</div>
<table class="grid">
    <tbody>
    <tr><td>Security deposit held</td><td class="r b" style="width: 110pt;">₹ <?= $m($voucher['deposit_held']) ?></td></tr>
    <?php foreach ($adjustments as $a): ?>
        <tr><td>Less: <?= e($a['label']) ?></td><td class="r" style="color:#991b1b;">− ₹ <?= $m($a['amount']) ?></td></tr>
    <?php endforeach ?>
    <?php if ($adjustments === []): ?><tr><td class="muted">No adjustments</td><td class="r muted">—</td></tr><?php endif ?>
    </tbody>
    <tfoot><tr><td style="background:#0b1b3f; color:#fff; font-size: 10pt;">Amount refunded</td><td class="r" style="background:#0b1b3f; color:#fff; font-size: 10pt;">₹ <?= $m($voucher['refund_amount']) ?></td></tr></tfoot>
</table>
<div class="words mt8"><div class="k">Amount refunded in words</div><div class="v"><?= e($voucher['amount_words']) ?></div></div>

<table class="kv mt12" style="width: 60%;">
    <?php if ($modeLabel !== null): ?><tr><td class="k">Refund mode</td><td class="v"><?= e($modeLabel) ?></td></tr><?php endif ?>
    <?php if (!empty($voucher['reference_no'])): ?><tr><td class="k">Reference</td><td class="v mono"><?= e($voucher['reference_no']) ?></td></tr><?php endif ?>
    <?php if (!empty($voucher['notes'])): ?><tr><td class="k">Notes</td><td class="v" style="font-weight: normal;"><?= e($voucher['notes']) ?></td></tr><?php endif ?>
</table>

<table class="mt16">
    <tr>
        <td style="width: 50%; vertical-align: bottom;">
            <div style="border-top: 0.8pt solid #9ca3af; width: 150pt; padding-top: 2pt; margin-top: 44pt;" class="small c">Received by (visitor signature)</div>
        </td>
        <td style="width: 50%; vertical-align: bottom;"><?= $this->partial('pdf/partials/signature') ?></td>
    </tr>
</table>
<div class="foot"><?= e($supplier['footer'] ?? '') ?> A security deposit is not a taxable supply; adjustments for services are invoiced separately where GST applies.</div>
