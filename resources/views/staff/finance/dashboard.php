<?php
/**
 * Finance dashboard page (/staff/finance).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $finance FinanceOverview::build()
 */
$this->layout('layouts/staff', ['title' => 'Finance overview', 'subtitle' => 'Collections, dues, GST and the queues that need Finance today.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Finance']]]);
?>
<?php $this->start('head') ?>
<script defer src="<?= e(asset('assets/vendor/chart.umd.min.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/finance.js')) ?>"></script>
<?php $this->stop() ?>
<?php $this->start('actions') ?>
<div class="flex gap-1.5" role="group" aria-label="Financial year">
    <?php foreach ($finance['fys'] as $fy): ?>
        <a href="<?= e(url('staff.finance.dashboard', ['fy' => $fy])) ?>" class="chip !py-1.5 text-xs <?= $fy === $finance['fy'] ? 'chip-active' : '' ?>">FY <?= e($fy) ?></a>
    <?php endforeach ?>
</div>
<?php $this->stop() ?>
<?= $this->partial('staff/finance/overview') ?>
