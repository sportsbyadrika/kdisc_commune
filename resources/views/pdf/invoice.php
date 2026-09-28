<?php
/**
 * GST tax invoice (spec 7.3 / 9) — rendered by Finance\FinanceDocuments::invoicePdf().
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $invoice   invoices row (snapshots)
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $summary GstMath::summary()
 * @var array<string, string> $supplier
 * @var bool $inter
 * @var string $qr data URI
 * @var string|null $logo
 * @var string|null $signature
 * @var string|null $seal
 * @var bool $duplicate
 * @var array<string, mixed>|null $booking  booking row (category, seat codes)
 * @var list<array<string, mixed>> $payments  verified payments covered
 */
use App\Services\Finance\GstMath;
use App\Support\IndianStates;

$this->layout('pdf/layout', ['title' => 'Tax invoice ' . $invoice['invoice_no'], 'duplicate' => $duplicate]);
$pos = (string) $invoice['place_of_supply'];
$m = static fn ($v) => number_format((float) $v, 2);
?>
<?= $this->partial('pdf/partials/header', ['docTitle' => 'TAX INVOICE', 'docTag' => 'Original for recipient']) ?>

<table class="mt12">
    <tr>
        <td style="width: 50%;">
            <div class="box">
                <div class="box-title">Billed to (recipient)</div>
                <div class="box-body">
                    <div class="b" style="font-size: 9.5pt; color:#0b1b3f;"><?= e($invoice['customer_name']) ?></div>
                    <?php if (!empty($invoice['customer_address'])): ?><div class="muted"><?= nl2br(e($invoice['customer_address'])) ?></div><?php endif ?>
                    <table class="kv mt4">
                        <tr><td class="k">GSTIN</td><td class="v <?= $invoice['customer_gstin'] ? 'mono' : '' ?>"><?= e($invoice['customer_gstin'] ?: 'Unregistered (B2C)') ?></td></tr>
                        <?php if (!empty($invoice['customer_pan'])): ?><tr><td class="k">PAN</td><td class="v mono"><?= e($invoice['customer_pan']) ?></td></tr><?php endif ?>
                        <tr><td class="k">Unique ID</td><td class="v mono"><?= e($invoice['customer_unique_id'] ?? '—') ?></td></tr>
                        <tr><td class="k">State</td><td class="v"><?= e(IndianStates::name((string) ($invoice['customer_state_code'] ?? $pos))) ?> (<?= e($invoice['customer_state_code'] ?? $pos) ?>)</td></tr>
                    </table>
                </div>
            </div>
        </td>
        <td class="gap"></td>
        <td style="width: 50%;">
            <div class="box">
                <div class="box-title">Invoice details</div>
                <div class="box-body">
                    <table class="kv">
                        <tr><td class="k">Invoice no.</td><td class="v mono" style="font-size: 9.5pt; color:#0b1b3f;"><?= e($invoice['invoice_no']) ?></td></tr>
                        <tr><td class="k">Invoice date</td><td class="v"><?= e(format_date((string) $invoice['invoice_date'], 'd M Y')) ?></td></tr>
                        <tr><td class="k">Booking no.</td><td class="v mono"><?= e($invoice['booking_no'] ?? '—') ?></td></tr>
                        <tr><td class="k"><?= $invoice['kind'] === 'rent' ? 'Rent period' : 'Service period' ?></td><td class="v"><?= e(format_date((string) $invoice['period_start']) . ' – ' . format_date((string) $invoice['period_end'])) ?></td></tr>
                        <tr><td class="k">Place of supply</td><td class="v"><?= e(IndianStates::name($pos)) ?> (<?= e($pos) ?>)</td></tr>
                        <tr><td class="k">Reverse charge</td><td class="v">No</td></tr>
                    </table>
                </div>
            </div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th style="width: 12pt;">#</th>
        <th style="width: <?= $inter ? 178 : 132 ?>pt;">Description of service</th>
        <th style="width: 32pt;">SAC</th>
        <th class="r" style="width: 26pt;">Qty</th>
        <th class="r" style="width: 46pt;">Rate (₹)</th>
        <th class="r" style="width: 52pt;">Taxable (₹)</th>
        <?php if ($inter): ?>
            <th class="r" style="width: 50pt;">IGST (₹)</th>
        <?php else: ?>
            <th class="r" style="width: 44pt;">CGST (₹)</th>
            <th class="r" style="width: 44pt;">SGST (₹)</th>
        <?php endif ?>
        <th class="r" style="width: 54pt;">Amount (₹)</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i => $it): $rate = (float) $it['gst_rate']; ?>
        <tr class="<?= $i % 2 ? 'alt' : '' ?>">
            <td class="c muted"><?= $i + 1 ?></td>
            <td><div class="desc"><?= e($it['description']) ?></div><?php if (!empty($it['detail'])): ?><div class="detail"><?= e($it['detail']) ?></div><?php endif ?></td>
            <td class="mono"><?= e($it['sac']) ?></td>
            <td class="r"><?= e(rtrim(rtrim(number_format((float) $it['qty'], 2), '0'), '.')) ?><div class="detail"><?= e($it['unit'] ?? '') ?></div></td>
            <td class="r"><?= $m($it['rate']) ?></td>
            <td class="r b"><?= $m($it['taxable_value']) ?></td>
            <?php if ($inter): ?>
                <td class="r"><?= $m($it['igst']) ?><div class="detail">@ <?= e(GstMath::rateLabel($rate)) ?></div></td>
            <?php else: ?>
                <td class="r"><?= $m($it['cgst']) ?><div class="detail">@ <?= e(GstMath::rateLabel($rate / 2)) ?></div></td>
                <td class="r"><?= $m($it['sgst']) ?><div class="detail">@ <?= e(GstMath::rateLabel($rate / 2)) ?></div></td>
            <?php endif ?>
            <td class="r b"><?= $m($it['total']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot>
    <tr>
        <td colspan="5" class="r">Total</td>
        <td class="r"><?= $m($invoice['taxable_value']) ?></td>
        <?php if ($inter): ?>
            <td class="r"><?= $m($invoice['igst']) ?></td>
        <?php else: ?>
            <td class="r"><?= $m($invoice['cgst']) ?></td><td class="r"><?= $m($invoice['sgst']) ?></td>
        <?php endif ?>
        <td class="r"><?= $m((float) $invoice['taxable_value'] + (float) $invoice['cgst'] + (float) $invoice['sgst'] + (float) $invoice['igst']) ?></td>
    </tr>
    </tfoot>
</table>

<table class="mt12">
    <tr>
        <td style="width: 56%; padding-right: 12pt;">
            <div class="words">
                <div class="k">Amount in words</div>
                <div class="v"><?= e($invoice['amount_words']) ?></div>
            </div>
            <div class="section-title">Tax summary</div>
            <table class="grid">
                <thead><tr><th>SAC</th><th class="r">Rate</th><th class="r">Taxable (₹)</th><?php if ($inter): ?><th class="r">IGST (₹)</th><?php else: ?><th class="r">CGST (₹)</th><th class="r">SGST (₹)</th><?php endif ?><th class="r">Tax (₹)</th></tr></thead>
                <tbody>
                <?php foreach ($summary as $s): ?>
                    <tr><td class="mono"><?= e($s['sac']) ?></td><td class="r"><?= e(GstMath::rateLabel($s['rate'])) ?></td><td class="r"><?= $m($s['taxable']) ?></td>
                        <?php if ($inter): ?><td class="r"><?= $m($s['igst']) ?></td><?php else: ?><td class="r"><?= $m($s['cgst']) ?></td><td class="r"><?= $m($s['sgst']) ?></td><?php endif ?>
                        <td class="r b"><?= $m($s['tax']) ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table>
            <?php if (!empty($supplier['bank_account_no']) || !empty($supplier['bank_upi'])): ?>
                <div class="section-title">Bank details</div>
                <p class="note"><?= e(implode(' · ', array_filter([$supplier['bank_account_name'] ?? '', trim(implode(', ', array_filter([$supplier['bank_name'] ?? '', $supplier['bank_branch'] ?? ''])))]))) ?><br>
                    <?php if (!empty($supplier['bank_account_no'])): ?>A/c no. <b class="mono"><?= e($supplier['bank_account_no']) ?></b><?php endif ?>
                    <?php if (!empty($supplier['bank_ifsc'])): ?> · IFSC <b class="mono"><?= e($supplier['bank_ifsc']) ?></b><?php endif ?>
                    <?php if (!empty($supplier['bank_upi'])): ?> · UPI <b class="mono"><?= e($supplier['bank_upi']) ?></b><?php endif ?></p>
            <?php endif ?>
            <?php if (!empty($supplier['terms'])): ?>
                <div class="section-title">Terms</div>
                <p class="note"><?= e($supplier['terms']) ?></p>
            <?php endif ?>
        </td>
        <td style="width: 44%;">
            <table class="totals box">
                <tr class="line"><td class="k">Taxable value</td><td class="v">₹ <?= $m($invoice['taxable_value']) ?></td></tr>
                <?php if ($inter): ?>
                    <tr class="line"><td class="k">IGST</td><td class="v">₹ <?= $m($invoice['igst']) ?></td></tr>
                <?php else: ?>
                    <tr class="line"><td class="k">CGST</td><td class="v">₹ <?= $m($invoice['cgst']) ?></td></tr>
                    <tr class="line"><td class="k">SGST</td><td class="v">₹ <?= $m($invoice['sgst']) ?></td></tr>
                <?php endif ?>
                <?php if ((float) $invoice['round_off'] !== 0.0): ?>
                    <tr class="line"><td class="k">Round off</td><td class="v"><?= (float) $invoice['round_off'] < 0 ? '−' : '' ?>₹ <?= $m(abs((float) $invoice['round_off'])) ?></td></tr>
                <?php endif ?>
                <tr class="grand"><td>Invoice total</td><td class="r b">₹ <?= $m($invoice['total']) ?></td></tr>
            </table>
            <?php if ($payments !== []): ?>
                <p class="small muted mt4">Received: <?php foreach ($payments as $i => $p): ?><?= $i ? '; ' : '' ?><?= e(money($p['amount'], 2)) ?> on <?= e(format_date((string) $p['paid_on'], 'd M Y')) ?> (<?= e(strtoupper((string) $p['mode'])) ?><?= !empty($p['reference_no']) ? ' ' . e($p['reference_no']) : '' ?>)<?php endforeach ?> — <b style="color:#065f46">Paid</b></p>
            <?php else: ?>
                <p class="small mt4"><b style="color:#065f46">Paid</b> <span class="muted">— rent for this period received and verified.</span></p>
            <?php endif ?>
            <table class="mt12">
                <tr>
                    <td class="c qr" style="width: 40%;"><img src="<?= e($qr) ?>" alt="" style="width: 66pt; height: 66pt;"><div class="qr-cap">Scan to verify</div></td>
                    <td style="width: 60%; vertical-align: bottom;"><?= $this->partial('pdf/partials/signature') ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="foot"><?= e($supplier['footer'] ?? '') ?> Services: co-working space rental (SAC <?= e($supplier['sac'] ?? '997212') ?>). Security deposits are not part of this invoice.</div>
