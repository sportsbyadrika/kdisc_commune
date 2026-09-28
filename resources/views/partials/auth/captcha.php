<?php
/**
 * Math captcha field (App\Services\Auth\Captcha / LoginCaptcha). Used on registration and — after repeated failed
 * attempts — on both sign-in forms.
 *
 * @var string $question  e.g. "What is 4 + 7?"
 * @var string|null $note small line under the label (why it is shown)
 */
?>
<div class="rounded-2xl border border-line bg-surface p-4" data-test="captcha">
    <label for="f-captcha" class="label flex items-center gap-2"><?= icon('shield-check', 'size-4 text-brand-600') ?> Quick check: <?= e($question) ?></label>
    <?php if (!empty($note)): ?><p id="f-captcha-note" class="-mt-0.5 mb-2 text-xs text-muted"><?= e($note) ?></p><?php endif ?>
    <input id="f-captcha" name="captcha" inputmode="numeric" autocomplete="off" required
           class="<?= e(class_names('input w-32', ['input-error' => errors('captcha') !== null])) ?>" placeholder="Answer"
           <?= attrs(['aria-invalid' => errors('captcha') !== null ? 'true' : null, 'aria-describedby' => errors('captcha') !== null ? 'f-captcha-error' : (!empty($note) ? 'f-captcha-note' : null)]) ?>>
    <?php if (errors('captcha')): ?><p id="f-captcha-error" class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e(errors('captcha')) ?></p><?php endif ?>
</div>
