<?php
/**
 * Booking allotment letter (spec 9) — BookingDocuments::allotmentLetter().
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $booking  BookingDirectory row (category_name, customer_name, unique_id)
 * @var array<string, mixed> $customer safe customer row
 * @var list<array<string, mixed>> $seats current booking_seats rows (code, label, zone_name, floor_name)
 * @var list<array<string, mixed>> $addons
 * @var list<array{name: string, image: string}> $maps
 * @var array<string, mixed> $dues
 * @var bool $deposit
 * @var array<string, string> $supplier
 * @var string $qr
 * @var string $today
 */
$this->layout('pdf/layout', ['title' => 'Allotment letter ' . $booking['booking_no'], 'duplicate' => false]);
$hourly = $booking['start_time'] !== null;
$period = $hourly
    ? format_date((string) $booking['start_date'], 'l, d M Y') . ', ' . substr((string) $booking['start_time'], 0, 5) . ' – ' . substr((string) $booking['end_time'], 0, 5)
    : format_date((string) $booking['start_date'], 'd M Y') . ' to ' . format_date((string) $booking['end_date'], 'd M Y');
$first = explode(' ', (string) $customer['name'])[0];
$address = trim(implode(', ', array_filter([(string) ($customer['address'] ?? ''), (string) ($customer['city'] ?? ''), (string) ($customer['pincode'] ?? '')])));
?>
<?= $this->partial('pdf/partials/header', ['docTitle' => 'ALLOTMENT LETTER', 'docTag' => 'Booking confirmed']) ?>

<table class="mt12">
    <tr>
        <td style="width: 62%;">
            <p class="muted small">Ref. ALT/<?= e($booking['booking_no']) ?> &nbsp;·&nbsp; <?= e(format_date($today, 'd M Y')) ?></p>
            <p class="mt8 muted upper small">To</p>
            <p class="b" style="font-size: 11pt; color:#0b1b3f;"><?= e($customer['name']) ?></p>
            <?php if ($address !== ''): ?><p class="muted"><?= e($address) ?></p><?php endif ?>
            <p class="mt4">Unique Visitor ID <b class="mono"><?= e($customer['unique_id'] ?? '—') ?></b></p>
        </td>
        <td style="width: 38%;" class="r qr">
            <img src="<?= e($qr) ?>" alt="" style="width: 62pt; height: 62pt;">
            <div class="qr-cap">Show this QR at the front desk to check in</div>
        </td>
    </tr>
</table>

<p class="mt12 b" style="color:#0b1b3f;">Subject: Allotment of workspace at <?= e($supplier['trade_name'] ?: 'Commune') ?> — booking <?= e($booking['booking_no']) ?></p>
<p class="mt8">Dear <?= e($first) ?>,</p>
<p class="mt4">We are pleased to confirm the allotment of the following workspace to you. Your payment has been received and the seats below are reserved for you for the period stated. Please read the conditions of use overleaf/below and keep this letter for your records.</p>

<table class="grid mt12">
    <tbody>
    <tr><td class="muted" style="width: 30%;">Booking no.</td><td class="b mono"><?= e($booking['booking_no']) ?></td></tr>
    <tr><td class="muted">Space</td><td class="b"><?= e($booking['category_name']) ?></td></tr>
    <tr><td class="muted"><?= $hourly ? 'Date &amp; time' : 'Tenure' ?></td><td class="b"><?= e($period) ?></td></tr>
    <tr><td class="muted">Allotted <?= count($seats) === 1 ? 'unit' : 'units' ?></td><td>
        <?php foreach ($seats as $i => $s): ?><?= $i ? '<br>' : '' ?><b class="mono"><?= e($s['code']) ?></b> <span class="muted">— <?= e(trim(($s['label'] && $s['label'] !== $s['code'] ? $s['label'] . ', ' : '') . $s['zone_name'] . ', ' . $s['floor_name'])) ?></span><?php endforeach ?>
    </td></tr>
    <?php if ($addons !== []): ?>
        <tr><td class="muted">Add-ons</td><td><?= e(implode(', ', array_map(static fn (array $a) => ((float) $a['qty'] > 1 ? rtrim(rtrim((string) $a['qty'], '0'), '.') . ' × ' : '') . $a['name'], $addons))) ?></td></tr>
    <?php endif ?>
    <tr><td class="muted">Booking amount</td><td><b><?= e(money($booking['grand_total'], 2)) ?></b> <span class="muted">incl. GST</span></td></tr>
    <?php if ($deposit): ?>
        <tr><td class="muted">Security deposit</td><td><b><?= e(money($booking['deposit_amount'], 2)) ?></b> <span class="muted">— refundable at the end of the tenure</span></td></tr>
        <tr><td class="muted">Rent</td><td>Payable monthly on the 1st day of each rent period (<?= count($dues['schedule'] ?? []) ?> periods). A GST invoice is issued for every period once paid.</td></tr>
    <?php endif ?>
    <tr><td class="muted">Balance due now</td><td class="b"><?= ($dues['due_now'] ?? 0) > 0 ? e(money($dues['due_now'], 2)) : 'Nil' ?></td></tr>
    </tbody>
</table>

<?php foreach ($maps as $map): ?>
    <div style="page-break-inside: avoid;">
    <div class="section-title"><?= e($map['name']) ?> — your seats are highlighted</div>
    <div style="border: 0.8pt solid #d1d5db; border-radius: 4pt; padding: 4pt; text-align: center;">
        <img src="<?= e($map['image']) ?>" alt="Seat map" style="width: 100%;">
    </div>
    <p class="tiny muted mt4"><span style="display:inline-block;width:8pt;height:6pt;background:#1d4ed8;"></span> Allotted to you &nbsp; <span style="display:inline-block;width:8pt;height:6pt;background:#e5e7eb;border:0.5pt solid #9ca3af;"></span> Other seats &nbsp; Plan not to scale.</p>
    </div>
<?php endforeach ?>

<div class="section-title">Conditions of use</div>
<ol class="note clause" style="margin: 0; padding-left: 14pt;">
    <li>Check in at the front desk with your Unique Visitor ID QR code; the seats are for the named visitor / institution only and may not be sub-let or shared.</li>
    <li>Centre hours: <?= e((string) config('app.org.hours', 'Mon–Sat · 8:00 am – 8:00 pm')) ?>. The conference room is available only for the booked slot.</li>
    <?php if ($deposit): ?><li>Rent for each period is due on its first day; unpaid dues may be adjusted against the security deposit, which is refunded after the tenure ends.</li><?php endif ?>
    <li>Seat changes are made only by the Centre Manager (seat handover). Furniture and equipment must not be moved between zones.</li>
    <li>Keep the workspace clean and quiet; food and drinks are allowed in the pantry. Any damage to property will be charged.</li>
    <li>The allotment ends on <?= e(format_date((string) $booking['end_date'], 'd M Y')) ?>. Renew from your portal before that date to keep the same seats.</li>
</ol>

<table class="mt16">
    <tr>
        <td style="width: 55%; vertical-align: bottom;" class="note">For help, contact the front desk<?= !empty($supplier['phone']) ? ' at ' . e($supplier['phone']) : '' ?><?= !empty($supplier['email']) ? ' or ' . e($supplier['email']) : '' ?>.</td>
        <td style="width: 45%; vertical-align: bottom;"><?= $this->partial('pdf/partials/signature') ?></td>
    </tr>
</table>
<div class="foot"><?= e($supplier['footer'] ?? '') ?></div>
