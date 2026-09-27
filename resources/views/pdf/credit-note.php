<?php
/**
 * GST credit note CN/{FY}/{0001} against an invoice — FinanceDocuments::render('credit_note').
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $note
 * @var array<string, mixed> $invoice
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $summary
 * @var bool $inter
 * @var array<string, string> $supplier
 * @var string $qr
 * @var bool $duplicate
 */
use App\Enums\CreditNoteReason;
use App\Services\Finance\GstMath;
use App\Support\IndianStates;

$this->layout('pdf/layout', ['title' => 'Credit note ' . $note['credit_note_no'], 'duplicate' => $duplicate]);
$m = static fn ($v) => number_format((float) $v, 2);
$pos = (string) $note['place_of_supply'];
$reason = CreditNoteReason::tryFrom((string) $note['reason_code']);
?>
<?= $this->partial('pdf/partials/header', ['docTitle' => 'CREDIT NOTE', 'docTag' => 'Original for recipient']) ?>

<table class="mt12">
    <tr>
        <td style="width: 50%;">
            <div class="box">
                <div class="box-title">Issued to (recipient)</div>
                <div class="box-body">
                    <div class="b" style="font-size: 9.5pt; color:#0b1b3f;"><?= e($invoice['customer_name'] ?? $note['customer_name']) ?></div>
                    <?php if (!empty($invoice['customer_address'])): ?><div class="muted"><?= nl2br(e($invoice['customer_address'])) ?></div><?php endif ?>
                    <table class="kv mt4">
                        <tr><td class="k">GSTIN</td><td class="v mono"><?= e(($invoice['customer_gstin'] ?? '') ?: 'Unregistered (B2C)') ?></td></tr>
                        <tr><td class="k">Unique ID</td><td class="v mono"><?= e($invoice['customer_unique_id'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </td>
        <td class="gap"></td>
        <td style="width: 50%;">
            <div class="box">
                <div class="box-title">Credit note details</div>
                <div class="box-body">
                    <table class="kv">
                        <tr><td class="k">Credit note no.</td><td class="v mono" style="font-size: 9.5pt; color:#0b1b3f;"><?= e($note['credit_note_no']) ?></td></tr>
                        <tr><td class="k">Date</td><td class="v"><?= e(format_date((string) $note['note_date'])) ?></td></tr>
                        <tr><td class="k">Original invoice</td><td class="v mono"><?= e($invoice['invoice_no'] ?? '') ?> <span class="muted" style="font-family: 'DejaVu Sans'; font-weight: normal;">dt. <?= e(format_date((string) ($invoice['invoice_date'] ?? ''))) ?></span></td></tr>
                        <tr><td class="k">Booking no.</td><td class="v mono"><?= e($invoice['booking_no'] ?? '—') ?></td></tr>
                        <tr><td class="k">Place of supply</td><td class="v"><?= e(IndianStates::name($pos)) ?> (<?= e($pos) ?>)</td></tr>
                    </table>
                </div>
            </div>
        </td>
    </tr>
</table>

<div class="words mt12" style="background:#fdf2f8; border-left-color:#e11d74;">
    <div class="k" style="color:#be185d;">Reason · <?= e($reason?->label() ?? 'Correction') ?></div>
    <div class="v" style="font-weight: normal;"><?= e($note['reason']) ?></div>
</div>

<table class="items">
    <thead>
    <tr>
        <th style="width: 12pt;">#</th>
        <th style="width: <?= $inter ? 236 : 188 ?>pt;">Description (reversal of invoice line)</th>
        <th style="width: 32pt;">SAC</th>
        <th class="r" style="width: 62pt;">Taxable (₹)</th>
        <?php if ($inter): ?><th class="r" style="width: 62pt;">IGST (₹)</th><?php else: ?><th class="r" style="width: 56pt;">CGST (₹)</th><th class="r" style="width: 56pt;">SGST (₹)</th><?php endif ?>
        <th class="r" style="width: 64pt;">Amount (₹)</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i => $it): $rate = (float) $it['gst_rate']; ?>
        <tr class="<?= $i % 2 ? 'alt' : '' ?>">
            <td class="c muted"><?= $i + 1 ?></td>
            <td class="desc"><?= e($it['description']) ?></td>
            <td class="mono"><?= e($it['sac']) ?></td>
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
</table>

<table class="mt12">
    <tr>
        <td style="width: 58%; padding-right: 12pt;">
            <div class="words"><div class="k">Amount credited in words</div><div class="v"><?= e($note['amount_words']) ?></div></div>
            <p class="note mt8">Original invoice total ₹ <?= $m($invoice['total'] ?? 0) ?>; credited to date ₹ <?= $m($invoice['credited_total'] ?? 0) ?>. The GST shown above is reversed against the original invoice (Section 34, CGST Act).</p>
        </td>
        <td style="width: 42%;">
            <table class="totals box">
                <tr class="line"><td class="k">Taxable value</td><td class="v">₹ <?= $m($note['taxable_value']) ?></td></tr>
                <?php if ($inter): ?>
                    <tr class="line"><td class="k">IGST reversed</td><td class="v">₹ <?= $m($note['igst']) ?></td></tr>
                <?php else: ?>
                    <tr class="line"><td class="k">CGST reversed</td><td class="v">₹ <?= $m($note['cgst']) ?></td></tr>
                    <tr class="line"><td class="k">SGST reversed</td><td class="v">₹ <?= $m($note['sgst']) ?></td></tr>
                <?php endif ?>
                <?php if ((float) $note['round_off'] !== 0.0): ?><tr class="line"><td class="k">Round off</td><td class="v">₹ <?= $m($note['round_off']) ?></td></tr><?php endif ?>
                <tr class="grand"><td>Total credit</td><td class="r b">₹ <?= $m($note['total']) ?></td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="mt16">
    <tr>
        <td style="width: 50%;" class="qr"><img src="<?= e($qr) ?>" alt=""><div class="qr-cap">Credit note <?= e($note['credit_note_no']) ?></div></td>
        <td style="width: 50%; vertical-align: bottom;"><?= $this->partial('pdf/partials/signature') ?></td>
    </tr>
</table>
<div class="foot"><?= e($supplier['footer'] ?? '') ?></div>
