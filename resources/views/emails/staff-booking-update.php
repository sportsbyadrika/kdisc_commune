<?php
/**
 * Staff notice about a booking (new request, status change) — BookingNotifier::toStaff().
 *
 * @var App\Core\Template $this
 * @var string $name
 * @var array<string, mixed> $booking
 * @var string $message
 * @var string $url
 */
$this->layout('emails/layout', ['preheader' => $message]);
?>
<h1 style="margin:0 0 16px;font-size:22px;line-height:1.25;font-weight:800;">Booking <?= e($booking['booking_no']) ?></h1>
<p style="margin:0 0 8px;"><?= e($message) ?></p>
<?= $this->partial('emails/button', ['url' => $url, 'label' => 'Open in the staff console']) ?>
<?php $this->start('footer_note') ?>You receive this because you are front-desk / centre staff at Commune Kottarakara.<?php $this->stop() ?>
