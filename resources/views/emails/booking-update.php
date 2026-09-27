<?php
/**
 * Visitor booking email (status changes, seat handover, early exit, renewal reminders) — BookingNotifier.
 *
 * @var App\Core\Template $this
 * @var string $name
 * @var array<string, mixed> $booking   joined booking row (category_name, seat_codes)
 * @var string $headline
 * @var string $intro
 * @var array{label: string, url: string}|null $button
 * @var list<array{0: string, 1: string}>|null $rows extra detail rows
 */
$t = (array) config('mail.theme', []);
$this->layout('emails/layout', ['preheader' => 'Booking ' . $booking['booking_no'] . ' ' . $headline]);
$rows = [
    ['Booking no.', (string) $booking['booking_no']],
    ['Space', (string) ($booking['category_name'] ?? '')],
    ['Seats', (string) ($booking['seat_codes'] ?? '')],
    [$booking['start_time'] ? 'When' : 'Dates', $booking['start_time']
        ? format_date((string) $booking['start_date'], 'D, d M Y') . ' · ' . substr((string) $booking['start_time'], 0, 5) . '–' . substr((string) $booking['end_time'], 0, 5)
        : format_date((string) $booking['start_date'], 'd M Y') . ' → ' . format_date((string) $booking['end_date'], 'd M Y')],
    ...($rows ?? []),
];
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Booking <?= e($headline) ?></h1>
<p style="margin:0 0 20px;"><?= $name !== '' ? 'Hi ' . e(explode(' ', $name)[0]) . ', ' : '' ?><?= e($intro) ?></p>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid <?= e($t['line'] ?? '#e5e7eb') ?>;border-radius:12px;">
    <?php foreach ($rows as $i => [$k, $v]): ?>
        <tr><td style="padding:10px 14px;font-size:13px;color:<?= e($t['muted'] ?? '#6b7280') ?>;<?= $i ? 'border-top:1px solid ' . e($t['line'] ?? '#e5e7eb') . ';' : '' ?>width:40%;"><?= e($k) ?></td>
            <td style="padding:10px 14px;font-size:14px;font-weight:700;<?= $i ? 'border-top:1px solid ' . e($t['line'] ?? '#e5e7eb') . ';' : '' ?>"><?= e($v) ?></td></tr>
    <?php endforeach ?>
</table>
<?php if (!empty($button)): ?><?= $this->partial('emails/button', ['url' => $button['url'], 'label' => $button['label']]) ?><?php endif ?>
