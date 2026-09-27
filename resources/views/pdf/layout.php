<?php
/**
 * Root document for dompdf templates. Data: title, duplicate (bool → "DUPLICATE COPY" watermark), extraCss.
 * Templates call $this->layout('pdf/layout', [...]) and render tables only (no Tailwind, no flex/grid).
 *
 * @var App\Core\Template $this
 * @var string|null $title
 * @var bool|null $duplicate
 * @var string|null $extraCss
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= e($title ?? 'Document') ?></title>
    <style><?= App\Services\Pdf\PdfService::css() ?><?= $extraCss ?? '' ?></style>
</head>
<body>
<?php if (!empty($duplicate)): ?>
    <div class="watermark">DUPLICATE COPY</div>
<?php endif ?>
<?= $this->section('content') ?>
</body>
</html>
