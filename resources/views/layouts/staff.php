<?php
/**
 * Staff console layout: same header style as the site + collapsible, role-aware sidebar.
 * Data: title, breadcrumb (optional), actions section.
 *
 * @var App\Core\Template $this
 * @var string|null $title
 */
$this->layout('layouts/base', ['bodyClass' => 'min-h-full bg-surface']);
$user = staff();
$role = $user !== null ? App\Enums\StaffRole::tryFrom((string) $user['role']) : null;
?>
<div x-data class="min-h-screen lg:flex">
    <?= $this->partial('partials/staff/sidebar', ['user' => $user, 'role' => $role]) ?>

    <div class="flex min-w-0 flex-1 flex-col">
        <?= $this->partial('partials/staff/header', ['user' => $user, 'role' => $role]) ?>
        <main id="main" class="<?= !empty($wide) ? 'mx-auto w-full max-w-[1680px] px-4 sm:px-6 lg:px-8' : 'container-page' ?> flex-1 py-8">
            <?php if (!empty($breadcrumb)): ?><div class="mb-4"><?= $this->component('breadcrumb', ['items' => $breadcrumb]) ?></div><?php endif ?>
            <?php if (!empty($title) && empty($hideTitle)): ?>
                <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <h1 class="text-3xl font-extrabold"><?= e($title) ?></h1>
                        <?php if (!empty($subtitle)): ?><p class="mt-1 text-muted"><?= e($subtitle) ?></p><?php endif ?>
                    </div>
                    <?php if ($this->hasSection('actions')): ?><div class="flex flex-wrap gap-2"><?= $this->section('actions') ?></div><?php endif ?>
                </div>
            <?php endif ?>
            <?= $this->partial('partials/flash', ['class' => 'mb-6']) ?>
            <?= $this->section('content') ?>
        </main>
        <footer class="container-page border-t border-line py-5 text-xs text-muted">Commune Staff Console · K-DISC · <?= date('Y') ?></footer>
    </div>
</div>
