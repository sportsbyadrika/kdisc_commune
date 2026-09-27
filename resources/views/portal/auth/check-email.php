<?php
/**
 * "Check your inbox" after register / forgot password, with a resend option.
 *
 * @var App\Core\Template $this
 * @var string $email
 * @var string $heading
 * @var string $lead
 */
$this->layout('layouts/auth', [
    'panelEyebrow' => 'Visitor portal',
    'panelTitle' => 'Almost there.',
    'panelText' => 'Open the email from Commune and follow the link to set your password.',
]);
?>
<div class="text-center sm:text-left">
    <span class="inline-grid size-16 place-items-center rounded-2xl bg-brand-50 text-brand-600"><?= icon('mail-check', 'size-8') ?></span>
    <h1 class="mt-6 text-3xl font-extrabold"><?= e($heading) ?></h1>
    <?php if ($email !== ''): ?><p class="mt-2 font-semibold text-ink"><?= e($email) ?></p><?php endif ?>
    <p class="mt-3 text-muted"><?= e($lead) ?></p>
    <ul class="mt-6 space-y-2 text-left text-sm text-ink/80">
        <li class="flex gap-2"><?= icon('check', 'mt-0.5 size-4 shrink-0 text-emerald-600') ?> Look in spam / promotions if it hasn’t arrived in a few minutes.</li>
        <li class="flex gap-2"><?= icon('check', 'mt-0.5 size-4 shrink-0 text-emerald-600') ?> The link can be used only once.</li>
    </ul>
</div>
<div class="card card-body mt-8">
    <p class="text-sm font-semibold">Didn’t get it?</p>
    <form method="post" action="<?= e(url('portal.password.resend')) ?>" class="mt-3 flex flex-col gap-3 sm:flex-row">
        <?= csrf_field() ?>
        <input type="email" name="email" value="<?= e($email) ?>" required placeholder="you@example.com" class="input flex-1" aria-label="Email">
        <button type="submit" class="btn btn-outline"><?= icon('refresh-cw', 'size-4') ?> Resend link</button>
    </form>
</div>
<p class="mt-8 text-center text-sm text-muted"><a href="<?= e(url('portal.login')) ?>" class="font-semibold text-brand-600 hover:underline">← Back to sign in</a></p>
