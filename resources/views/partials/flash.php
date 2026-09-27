<?php
/**
 * Flash messages set with redirect()->with('success'|'error'|'warning'|'info', '...')
 * plus a summary when validation failed. Included by every layout.
 *
 * @var App\Core\Template $this
 * @var string|null $class
 */
$session = App\Core\Session::current();
$messages = [];
foreach (['success', 'error', 'warning', 'info'] as $tone) {
    $msg = $session?->getFlash($tone);
    if (is_string($msg) && $msg !== '') {
        $messages[] = [$tone, $msg];
    }
}
$errorCount = count(errors());
if ($messages === [] && $errorCount === 0) {
    return;
}
?>
<div class="space-y-3 <?= e($class ?? '') ?>" role="status">
    <?php foreach ($messages as [$tone, $msg]): ?>
        <?= $this->component('alert', ['tone' => $tone === 'error' ? 'danger' : $tone, 'message' => $msg, 'dismissible' => true]) ?>
    <?php endforeach ?>
    <?php if ($errorCount > 0 && empty($hideErrorSummary)): ?>
        <?= $this->component('alert', ['tone' => 'danger', 'message' => $errorCount === 1 ? 'Please correct the highlighted field.' : "Please correct the {$errorCount} highlighted fields.", 'dismissible' => true]) ?>
    <?php endif ?>
</div>
