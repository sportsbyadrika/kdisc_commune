<?php
/**
 * Generic error page. Specific pages (404.php, 403.php, 419.php, 500.php) reuse this markup.
 * Rendered by App\Core\ErrorHandler — keep it free of DB calls so it works when the DB is down.
 *
 * @var App\Core\Template $this
 * @var int $status
 * @var string $title
 * @var string|null $message
 * @var string|null $icon
 */
$this->layout('layouts/base', ['title' => $title, 'bodyClass' => 'min-h-full bg-surface']);
$isStaff = str_starts_with((string) ($current_path ?? ''), '/staff');
?>
<main id="main" class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-16">
    <div class="absolute inset-0 -z-10 bg-[radial-gradient(40rem_24rem_at_50%_-10%,var(--color-brand-100),transparent_70%)]"></div>
    <div class="w-full max-w-lg text-center">
        <a href="<?= e(url('/')) ?>" class="inline-flex"><?= $this->partial('partials/logo') ?></a>
        <p class="mt-12 font-display text-[7rem] leading-none font-extrabold tracking-tighter text-brand-900/10 select-none sm:text-[9rem]"><?= (int) $status ?></p>
        <div class="-mt-10 inline-grid size-16 place-items-center rounded-2xl bg-white text-accent-500 shadow-[var(--shadow-card)] sm:-mt-14"><?= icon($icon ?? 'circle-alert', 'size-8') ?></div>
        <h1 class="mt-6 text-3xl font-extrabold sm:text-4xl"><?= e($title) ?></h1>
        <p class="mx-auto mt-3 max-w-md text-muted"><?= e($message ?? $default ?? 'Something unexpected happened.') ?></p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="<?= e($isStaff ? url('/staff/dashboard') : url('/')) ?>" class="btn btn-primary"><?= icon('house', 'size-4') ?> <?= $isStaff ? 'Go to dashboard' : 'Back to home' ?></a>
            <?php if (!$isStaff): ?><a href="<?= e(url('/contact')) ?>" class="btn btn-outline">Contact us</a><?php endif ?>
        </div>
    </div>
</main>
