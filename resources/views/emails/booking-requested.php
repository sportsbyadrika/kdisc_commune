<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var array<string, mixed> $booking
 * @var array<string, mixed> $quote  Quote::toArray()
 * @var string $url
 */
$t = (array) config('mail.theme', []);
$this->layout('emails/layout', ['preheader' => 'Booking request ' . $booking['booking_no'] . ' received — we will confirm after review.']);
$rows = [
    ['Booking no.', (string) $booking['booking_no']],
    ['Space', (string) $quote['category']['label']],
    ['Seats', implode(', ', array_column($quote['seats'], 'code'))],
    [$booking['start_time'] ? 'When' : 'Dates', (string) $quote['period']['label']],
    ['Duration', (string) $quote['duration']['label']],
];
foreach ($quote['addons'] as $a) {
    $rows[] = ['Add-on', $a['name'] . ($a['qty'] > 1 ? ' × ' . $a['qty'] : '')];
}
$rows[] = ['Total (incl. GST)', money($quote['totals']['grand'], fmod((float) $quote['totals']['grand'], 1.0) ? 2 : 0)];
$rows[] = ['Payment', $quote['payment']['rule'] === 'advance' ? 'Advance ' . money($quote['payment']['payable_now']) : 'Security deposit ' . money($quote['payment']['deposit'])];
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Booking request received</h1>
<p style="margin:0 0 20px;">Thanks<?= $name !== '' ? ', ' . e(explode(' ', $name)[0]) : '' ?>! Your seats are reserved while our Centre Manager reviews the request. We’ll email you once it’s approved — then pay at the front desk to confirm.</p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid <?= e($t['line'] ?? '#e5e7eb') ?>;border-radius:12px;">
    <?php foreach ($rows as $i => [$k, $v]): ?>
        <tr><td style="padding:10px 14px;font-size:13px;color:<?= e($t['muted'] ?? '#6b7280') ?>;<?= $i ? 'border-top:1px solid ' . e($t['line'] ?? '#e5e7eb') . ';' : '' ?>width:40%;"><?= e($k) ?></td>
            <td style="padding:10px 14px;font-size:14px;font-weight:700;<?= $i ? 'border-top:1px solid ' . e($t['line'] ?? '#e5e7eb') . ';' : '' ?>"><?= e($v) ?></td></tr>
    <?php endforeach ?>
</table>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'View my booking']) ?>
