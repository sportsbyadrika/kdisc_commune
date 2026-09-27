<?php
/** @var string $uniqueId */
$t = (array) config('mail.theme', []);
?>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:22px 0;background:<?= e($t['surface'] ?? '') ?>;border:1px solid <?= e($t['line'] ?? '') ?>;border-radius:14px;">
    <tr><td style="padding:16px 20px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:<?= e($t['muted'] ?? '') ?>;">Unique Visitor ID</div>
        <div style="margin-top:4px;font-size:22px;font-weight:800;letter-spacing:.02em;color:<?= e($t['brand_dark'] ?? '') ?>;font-family:Menlo,Consolas,monospace;"><?= e($uniqueId) ?></div>
    </td></tr>
</table>
