<?php
/**
 * Supplier header band for finance documents.
 *
 * @var array<string, string> $supplier  FinanceSettings::supplier() snapshot
 * @var string|null $logo                data URI
 * @var string $docTitle                 e.g. TAX INVOICE
 * @var string|null $docTag              e.g. Original for recipient
 * @var bool|null $duplicate
 */
use App\Support\IndianStates;

$state = $supplier['state'] ?? IndianStates::name($supplier['state_code'] ?? '32');
?>
<table class="head">
    <tr>
        <td style="width: 44pt;">
            <?php if (!empty($logo)): ?>
                <img src="<?= e($logo) ?>" alt="" style="max-width: 44pt; max-height: 44pt;">
            <?php else: ?>
                <div class="mark">C<span>.</span></div>
            <?php endif ?>
        </td>
        <td style="padding-left: 8pt;">
            <div class="org-name"><?= e($supplier['legal_name'] ?? '') ?></div>
            <?php if (!empty($supplier['trade_name'])): ?><div class="b brand" style="font-size: 8pt;"><?= e($supplier['trade_name']) ?></div><?php endif ?>
            <div class="org-sub"><?= e($supplier['address'] ?? '') ?></div>
            <div class="org-sub">
                GSTIN: <b style="color:#111827"><?= e(($supplier['gstin'] ?? '') !== '' ? $supplier['gstin'] : 'Registration pending') ?></b>
                <?php if (!empty($supplier['pan'])): ?> &nbsp;·&nbsp; PAN: <b style="color:#111827"><?= e($supplier['pan']) ?></b><?php endif ?>
                &nbsp;·&nbsp; State: <?= e($state) ?> (<?= e($supplier['state_code'] ?? '32') ?>)
            </div>
            <?php if (!empty($supplier['email']) || !empty($supplier['phone'])): ?>
                <div class="org-sub"><?= e(implode('  ·  ', array_filter([$supplier['email'] ?? '', $supplier['phone'] ?? '']))) ?></div>
            <?php endif ?>
        </td>
        <td style="width: 196pt;" class="r">
            <div class="doc-title"><?= e($docTitle) ?></div>
            <?php if (!empty($duplicate)): ?>
                <div class="doc-tag" style="border-color:#e11d74;color:#e11d74;">Duplicate copy</div>
            <?php elseif (!empty($docTag)): ?>
                <div class="doc-tag"><?= e($docTag) ?></div>
            <?php endif ?>
        </td>
    </tr>
</table>
<div class="rule"></div><div class="rule-accent"></div>
