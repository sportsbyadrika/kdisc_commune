<?php
/**
 * Bulletproof email button + fallback link.
 * @var string $url
 * @var string $label
 */
$t = (array) config('mail.theme', []);
?>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 20px;">
    <tr><td style="border-radius:999px;background:<?= e($t['accent'] ?? '') ?>;">
        <a href="<?= e($url) ?>" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:700;color:<?= e($t['white'] ?? '') ?>;text-decoration:none;border-radius:999px;"><?= e($label) ?> &rarr;</a>
    </td></tr>
</table>
<p style="margin:0 0 8px;font-size:12px;color:<?= e($t['muted'] ?? '') ?>;">Button not working? Copy this link into your browser:<br>
    <a href="<?= e($url) ?>" style="color:<?= e($t['brand'] ?? '') ?>;word-break:break-all;"><?= e($url) ?></a></p>
