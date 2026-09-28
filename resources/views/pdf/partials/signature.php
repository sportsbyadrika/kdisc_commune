<?php
/**
 * "For K-DISC … Authorised Signatory" block (right aligned) with optional signature / seal images.
 *
 * @var array<string, string> $supplier
 * @var string|null $signature data URI
 * @var string|null $seal      data URI
 */
?>
<div class="sign">
    <div class="for">For <?= e($supplier['legal_name'] ?? 'K-DISC') ?></div>
    <table style="width: auto; margin-left: auto;"><tr>
        <?php if (!empty($seal)): ?><td style="padding-right: 6pt; vertical-align: bottom;"><img class="seal" src="<?= e($seal) ?>" alt=""></td><?php endif ?>
        <td style="vertical-align: bottom; text-align: center;">
            <?php if (!empty($signature)): ?><img class="sig" src="<?= e($signature) ?>" alt=""><?php else: ?><div class="space"></div><?php endif ?>
        </td>
    </tr></table>
    <div class="line">
        <?php if (!empty($supplier['signatory_name'])): ?><b><?= e($supplier['signatory_name']) ?></b><br><?php endif ?>
        <span class="small"><?= e(($supplier['signatory_designation'] ?? '') !== '' ? $supplier['signatory_designation'] . ' · ' : '') ?>Authorised Signatory</span>
    </div>
</div>
