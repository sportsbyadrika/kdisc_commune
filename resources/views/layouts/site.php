<?php
/**
 * Public website layout: utility bar + sticky header + main + footer.
 *
 * @var App\Core\Template $this
 */
$this->layout('layouts/base', ['bodyClass' => 'min-h-full bg-white flex flex-col']);
?>
<?= $this->partial('partials/site/header') ?>
<main id="main" class="flex-1">
    <?php if (empty($hideFlash)): ?>
        <div class="container-page"><?= $this->partial('partials/flash', ['class' => 'mt-6']) ?></div>
    <?php endif ?>
    <?= $this->section('content') ?>
</main>
<?= $this->partial('partials/site/footer') ?>
