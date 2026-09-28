<?php
/**
 * Finance document issued (invoice / receipt / credit note / deposit refund) — FinanceDocuments::issued().
 * The PDF is attached.
 *
 * @var App\Core\Template $this
 * @var string $name
 * @var string $title
 * @var string $number
 * @var float $amount
 * @var list<array{0: string, 1: string}> $rows
 * @var string $url
 */
$t = (array) config('mail.theme', []);
$this->layout('emails/layout', ['preheader' => $title . ' ' . $number . ' — ' . money($amount, 2)]);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;"><?= e($title) ?> <?= e($number) ?></h1>
<p style="margin:0 0 20px;"><?= $name !== '' ? 'Hi ' . e(explode(' ', $name)[0]) . ', ' : '' ?>your <?= e(strtolower($title)) ?> from Commune Kottarakara is attached as a PDF. You can download it again any time from your portal.</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid <?= e($t['line'] ?? '#e5e7eb') ?>;border-radius:12px;">
    <?php foreach ($rows as $i => [$k, $v]): ?>
        <tr><td style="padding:10px 14px;font-size:13px;color:<?= e($t['muted'] ?? '#6b7280') ?>;<?= $i ? 'border-top:1px solid ' . e($t['line'] ?? '#e5e7eb') . ';' : '' ?>width:40%;"><?= e($k) ?></td>
            <td style="padding:10px 14px;font-size:14px;font-weight:700;<?= $i ? 'border-top:1px solid ' . e($t['line'] ?? '#e5e7eb') . ';' : '' ?>"><?= e($v) ?></td></tr>
    <?php endforeach ?>
</table>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'My invoices & receipts']) ?>
