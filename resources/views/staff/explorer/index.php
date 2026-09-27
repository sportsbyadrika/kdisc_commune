<?php
/**
 * Receptionist mode of the Space Explorer.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $config
 */
$this->layout('layouts/staff', [
    'title' => 'Space Explorer',
    'wide' => true,
    'subtitle' => 'Pick seats for a visitor, see who sits where, and create the booking.',
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Space Explorer']],
]);
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/vendor/panzoom.min.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/explorer.js')) ?>"></script>
<?php $this->stop() ?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.bookings.index')) ?>" class="btn btn-outline"><?= icon('calendar-check', 'size-4') ?>Bookings</a>
<a href="<?= e(url('staff.visitors.create')) ?>" class="btn btn-brand"><?= icon('user-plus', 'size-4') ?>New visitor</a>
<?php $this->stop() ?>
<?= $this->partial('partials/space/explorer', ['config' => $config, 'staffMode' => true]) ?>
