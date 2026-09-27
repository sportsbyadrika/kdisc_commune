<?php
/**
 * Visitor ID card, CR80 (85.6 × 54 mm) — BookingDocuments::idCard(). Paper size is set by the caller.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer safe row
 * @var App\Enums\CustomerType $type
 * @var string|null $photo data URI
 * @var string $qr
 * @var array<string, string> $supplier
 * @var string|null $logo
 */
$verified = ($customer['kyc_status'] ?? '') === 'verified';
$css = '@page { margin: 0; } body { font-size: 6.4pt; } .card { width: 242pt; height: 153pt; } .band { background: #0b1b3f; color: #fff; }'
    . '.name { font-size: 9.6pt; font-weight: bold; color: #0b1b3f; line-height: 1.15; } .uid { font-family: "DejaVu Sans Mono"; font-size: 8.4pt; font-weight: bold; color: #0b1b3f; }';
$this->layout('pdf/layout', ['title' => 'Visitor ID ' . ($customer['unique_id'] ?? ''), 'extraCss' => $css]);
?>
<table class="card">
    <tr class="band">
        <td style="height: 28pt; padding: 0 9pt; vertical-align: middle;">
            <table><tr>
                <td style="width: 20pt; vertical-align: middle;">
                    <?php if (!empty($logo)): ?><img src="<?= e($logo) ?>" alt="" style="max-width: 20pt; max-height: 20pt;"><?php else: ?>
                        <div style="width: 18pt; height: 18pt; background: #fff; color: #0b1b3f; text-align: center; font-weight: bold; font-size: 10pt; line-height: 18pt; border-radius: 4pt;">C<span style="color:#e11d74">.</span></div>
                    <?php endif ?>
                </td>
                <td style="padding-left: 5pt; vertical-align: middle;">
                    <div style="font-size: 9pt; font-weight: bold; color: #fff;">Commune</div>
                    <div style="font-size: 5.2pt; color: #c7d2fe; letter-spacing: 0.6pt;">K-DISC · KOTTARAKARA</div>
                </td>
                <td class="r" style="vertical-align: middle;">
                    <span style="font-size: 5.6pt; font-weight: bold; letter-spacing: 0.8pt; color: #fff; background: <?= $verified ? '#059669' : '#6b7280' ?>; padding: 1.5pt 4pt; border-radius: 3pt;"><?= $verified ? 'KYC VERIFIED' : 'VISITOR' ?></span>
                </td>
            </tr></table>
        </td>
    </tr>
    <tr>
        <td style="padding: 7pt 9pt 0 9pt; height: 86pt;">
            <table><tr>
                <td style="width: 54pt;">
                    <?php if (!empty($photo)): ?>
                        <img src="<?= e($photo) ?>" alt="" style="width: 50pt; height: 62pt; border: 0.8pt solid #d1d5db; border-radius: 3pt;">
                    <?php else: ?>
                        <div style="width: 50pt; height: 62pt; border: 0.8pt dashed #9ca3af; border-radius: 3pt; background: #f3f4f6; color: #9ca3af; text-align: center; font-size: 18pt; font-weight: bold; padding-top: 18pt; height: 44pt;"><?= e(mb_strtoupper(mb_substr((string) $customer['name'], 0, 1))) ?></div>
                    <?php endif ?>
                </td>
                <td style="padding-left: 6pt;">
                    <div class="tiny muted upper">Visitor</div>
                    <div class="name"><?= e($customer['name']) ?></div>
                    <div class="muted" style="margin-top: 1pt;"><?= e($type->label()) ?></div>
                    <div class="tiny muted upper" style="margin-top: 6pt;">Unique Visitor ID</div>
                    <div class="uid"><?= e($customer['unique_id'] ?? '') ?></div>
                </td>
                <td style="width: 62pt;" class="r">
                    <img src="<?= e($qr) ?>" alt="" style="width: 60pt; height: 60pt;">
                </td>
            </tr></table>
        </td>
    </tr>
    <tr>
        <td style="height: 17pt; padding: 0 9pt; border-top: 0.8pt solid #e5e7eb; vertical-align: middle; font-size: 5.2pt;" class="muted">
            <?= e($supplier['trade_name'] ?: 'Commune Workspace, Kottarakara') ?> · Scan the QR at the front desk to check in. If found, please return to the front desk.
        </td>
    </tr>
</table>
