<?php
/**
 * Root HTML document. Extended by layouts/site, layouts/staff, layouts/auth.
 * Sections: content (required), head, scripts. Data: title, description, bodyClass.
 *
 * @var App\Core\Template $this
 * @var string|null $title
 */
$pageTitle = isset($title) && $title !== '' ? $title . ' · Commune Kottarakara' : 'Commune Kottarakara — Work near home';
?>
<!doctype html>
<html lang="en" class="h-full scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($description ?? 'Commune by K-DISC — flexible, affordable workspaces near home in Kottarakara. Hot desks, dedicated seats, cabins and a conference room.') ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#0b1b3f">
    <link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&display=swap">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <?= $this->section('head') ?>
    <script defer src="<?= e(asset('assets/js/app.js')) ?>"></script>
    <script defer src="<?= e(asset('assets/vendor/alpine.min.js')) ?>"></script>
</head>
<body class="<?= e($bodyClass ?? 'min-h-full bg-white') ?>">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:rounded-full focus:bg-white focus:px-4 focus:py-2 focus:shadow-lg">Skip to content</a>
    <?= $this->section('content') ?>
    <?= $this->section('scripts') ?>
</body>
</html>
