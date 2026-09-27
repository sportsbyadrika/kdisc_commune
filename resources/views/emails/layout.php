<?php
/**
 * Branded HTML email layout (table-based, inline styles — email clients ignore external CSS).
 * Colours come from config('mail.theme'), which mirrors the Tailwind @theme tokens.
 * Child templates: $this->layout('emails/layout', ['preheader' => '…']) and optional section 'footer_note'.
 * Helpers for children: $this->partial('emails/button', ['url' => …, 'label' => …]).
 *
 * @var App\Core\Template $this
 * @var string $subject
 * @var string|null $preheader
 */
$t = (array) config('mail.theme', []);
$org = (array) config('app.org', []);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($subject ?? 'Commune') ?></title>
</head>
<body style="margin:0;padding:0;background:<?= e($t['surface'] ?? '') ?>;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:<?= e($t['ink'] ?? '') ?>;-webkit-font-smoothing:antialiased;">
<!--notext--><div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= e($preheader ?? '') ?></div><!--/notext-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?= e($t['surface'] ?? '') ?>;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                <!--notext--><tr>
                    <td style="background:<?= e($t['brand_dark'] ?? '') ?>;border-radius:20px 20px 0 0;padding:26px 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                            <td style="width:38px;height:38px;background:<?= e($t['accent'] ?? '') ?>;border-radius:11px;text-align:center;vertical-align:middle;color:<?= e($t['white'] ?? '') ?>;font-weight:800;font-size:20px;line-height:38px;">C</td>
                            <td style="padding-left:12px;">
                                <div style="color:<?= e($t['white'] ?? '') ?>;font-size:19px;font-weight:800;letter-spacing:-0.01em;line-height:1.1;">Commune</div>
                                <div style="color:<?= e($t['white'] ?? '') ?>;opacity:.65;font-size:12px;line-height:1.4;"><?= e(($org['centre'] ?? 'Kottarakara') . ' · ' . ($org['short'] ?? 'K-DISC')) ?></div>
                            </td>
                        </tr></table>
                    </td>
                </tr><!--/notext-->
                <tr>
                    <td style="background:<?= e($t['white'] ?? '') ?>;padding:36px 32px 28px;border-left:1px solid <?= e($t['line'] ?? '') ?>;border-right:1px solid <?= e($t['line'] ?? '') ?>;font-size:15px;line-height:1.65;">
                        <?= $this->section('content') ?>
                    </td>
                </tr>
                <tr>
                    <td style="background:<?= e($t['white'] ?? '') ?>;border:1px solid <?= e($t['line'] ?? '') ?>;border-top:0;border-radius:0 0 20px 20px;padding:0 32px 28px;font-size:13px;line-height:1.6;color:<?= e($t['muted'] ?? '') ?>;">
                        <div style="border-top:1px solid <?= e($t['line'] ?? '') ?>;padding-top:18px;">
                            <?= $this->section('footer_note', 'Need help? Reply to this email or call the front desk.') ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:22px 12px;text-align:center;font-size:12px;line-height:1.6;color:<?= e($t['muted'] ?? '') ?>;">
                        <?= e($org['name'] ?? '') ?><br>
                        <?= e($org['address'] ?? '') ?><br>
                        <?= e($org['phone'] ?? '') ?> · <a href="mailto:<?= e($org['email'] ?? '') ?>" style="color:<?= e($t['brand'] ?? '') ?>;text-decoration:none;"><?= e($org['email'] ?? '') ?></a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
