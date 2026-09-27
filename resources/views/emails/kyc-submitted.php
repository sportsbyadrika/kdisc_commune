<?php
/**
 * @var App\Core\Template $this
 * @var string $name
 * @var string $uniqueId
 * @var string $portalUrl
 */
$this->layout('emails/layout', ['preheader' => 'Your Unique Visitor ID is ' . $uniqueId . '. KYC verification is in progress.']);
?>
<h1 style="margin:0 0 16px;font-size:24px;line-height:1.25;font-weight:800;">Profile received</h1>
<p style="margin:0;">Thanks<?= $name !== '' ? ', ' . e(explode(' ', $name)[0]) : '' ?>! Your profile and KYC documents have been submitted. Your Unique Visitor ID is:</p>
<?= $this->partial('emails/id-badge', ['uniqueId' => $uniqueId]) ?>
<p style="margin:0;">Our Centre Manager will verify your KYC, usually within one working day. You can already explore spaces and request bookings; a booking is confirmed once your KYC is verified.</p>
<?= $this->partial('emails/button', ['url' => $portalUrl, 'label' => 'Go to my dashboard']) ?>
