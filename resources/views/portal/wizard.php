<?php
/**
 * Profile wizard (spec 4.1 step 4): 1 basic · 2 identity & KYC · 3 documents · 4 review & submit.
 *
 * @var App\Core\Template $this
 * @var int $step
 * @var int $maxStep
 * @var array<string, mixed> $customer
 * @var App\Enums\CustomerType $type
 * @var App\Enums\KycStatus $kyc
 * @var array<string, mixed>|null $signatory
 * @var list<array<string, mixed>> $checklist
 * @var array<int, list<string>> $missing
 * @var bool $locked
 */
use App\Enums\CustomerType;
use App\Enums\KycStatus;

$this->layout('layouts/portal', [
    'heading' => (int) $customer['profile_step'] >= 4 ? 'Update your profile' : 'Complete your profile',
    'subheading' => $type === CustomerType::Individual ? 'Individual visitor · takes about 5 minutes' : 'Institution · keep PAN, GSTIN, TAN and signatory details handy',
]);
$docErrors = errors()['documents'] ?? [];
?>
<div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_320px]">
    <div class="min-w-0 space-y-6">
        <?= $this->partial('partials/visitor/stepper') ?>

        <?php if ($kyc === KycStatus::Rejected && !empty($customer['kyc_remarks'])): ?>
            <?= $this->component('alert', ['tone' => 'danger', 'title' => 'The Centre Manager asked for changes', 'message' => (string) $customer['kyc_remarks']]) ?>
        <?php elseif ($kyc === KycStatus::Verified && $step < 4): ?>
            <?= $this->component('alert', ['tone' => 'warning', 'title' => 'Your KYC is verified', 'message' => 'Changing identity or contact details sends your profile back to “KYC pending” until it is re-verified.']) ?>
        <?php endif ?>

        <?php if ($step === 1): ?>
            <form method="post" action="<?= e(url('portal.wizard.save', ['step' => 1])) ?>" class="card card-body space-y-6 sm:!p-8" novalidate>
                <?= csrf_field() ?>
                <div>
                    <h2 class="text-xl font-bold"><?= $type === CustomerType::Individual ? 'About you' : 'About your institution' ?></h2>
                    <p class="mt-1 text-sm text-muted">We prefilled what you gave us at registration.</p>
                </div>
                <?= $this->partial('partials/visitor/basic-fields', ['emailLocked' => true]) ?>
                <div class="flex justify-end border-t border-line pt-6">
                    <button type="submit" class="btn btn-primary btn-lg">Save &amp; continue <?= icon('arrow-right', 'size-4') ?></button>
                </div>
            </form>

        <?php elseif ($step === 2): ?>
            <form method="post" action="<?= e(url('portal.wizard.save', ['step' => 2])) ?>" class="card card-body space-y-6 sm:!p-8" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="state_code" value="<?= e($customer['state_code'] ?? '') ?>">
                <div>
                    <h2 class="text-xl font-bold">Identity &amp; KYC</h2>
                    <p class="mt-1 text-sm text-muted">Numbers are checked as you type. Aadhaar is encrypted and only its last 4 digits are ever shown.</p>
                </div>
                <?= $this->partial('partials/visitor/identity-fields') ?>
                <div class="flex items-center justify-between gap-3 border-t border-line pt-6">
                    <a href="<?= e(url('portal.wizard', ['step' => 1])) ?>" class="btn btn-ghost"><?= icon('arrow-left', 'size-4') ?> Back</a>
                    <button type="submit" class="btn btn-primary btn-lg">Save &amp; continue <?= icon('arrow-right', 'size-4') ?></button>
                </div>
            </form>

        <?php elseif ($step === 3): ?>
            <div class="card card-body space-y-6 sm:!p-8">
                <div>
                    <h2 class="text-xl font-bold">Upload documents</h2>
                    <p class="mt-1 text-sm text-muted">Clear photos or scans. On a phone, tap “Take photo” to use the camera. Files are stored privately and only our KYC team can open them.</p>
                </div>
                <?php if ($docErrors !== []): ?>
                    <?php $this->begin('alert', ['tone' => 'danger', 'title' => 'Some required documents are missing']) ?>
                        <ul class="mt-1 list-disc pl-5"><?php foreach ($docErrors as $m): ?><li><?= e($m) ?></li><?php endforeach ?></ul>
                    <?= $this->end() ?>
                <?php endif ?>
                <?php if ($locked): ?>
                    <?= $this->component('alert', ['tone' => 'info', 'message' => 'Your KYC is verified, so documents are locked. Contact the front desk if one needs replacing.']) ?>
                <?php endif ?>
                <?= $this->partial('partials/visitor/documents', ['context' => 'portal', 'locked' => $locked]) ?>
                <form method="post" action="<?= e(url('portal.wizard.save', ['step' => 3])) ?>" class="flex items-center justify-between gap-3 border-t border-line pt-6">
                    <?= csrf_field() ?>
                    <a href="<?= e(url('portal.wizard', ['step' => 2])) ?>" class="btn btn-ghost"><?= icon('arrow-left', 'size-4') ?> Back</a>
                    <button type="submit" class="btn btn-primary btn-lg">Continue to review <?= icon('arrow-right', 'size-4') ?></button>
                </form>
            </div>

        <?php else: ?>
            <div class="space-y-6">
                <div class="card card-body sm:!p-8">
                    <h2 class="text-xl font-bold">Review &amp; submit</h2>
                    <p class="mt-1 text-sm text-muted">Check everything once. After you submit, your Unique Visitor ID is issued and the Centre Manager verifies your KYC.</p>
                    <?php if ($missing !== []): ?>
                        <?php $this->begin('alert', ['tone' => 'warning', 'title' => 'A few things are still missing', 'class' => 'mt-5']) ?>
                            <ul class="mt-1 space-y-1">
                                <?php foreach ($missing as $s => $msgs): foreach ($msgs as $m): ?>
                                    <li class="flex flex-wrap items-center gap-2"><span><?= e($m) ?></span> <a class="font-semibold underline" href="<?= e(url('portal.wizard', ['step' => $s])) ?>">Fix in step <?= (int) $s ?></a></li>
                                <?php endforeach; endforeach ?>
                            </ul>
                        <?= $this->end() ?>
                    <?php endif ?>
                </div>

                <?= $this->partial('partials/visitor/summary', ['editBase' => 'portal']) ?>

                <section class="card overflow-hidden">
                    <header class="flex items-center justify-between border-b border-line bg-surface/60 px-5 py-3">
                        <h3 class="text-sm font-bold">Documents</h3>
                        <a href="<?= e(url('portal.wizard', ['step' => 3])) ?>" class="btn btn-ghost btn-sm"><?= icon('pencil', 'size-3.5') ?> Edit</a>
                    </header>
                    <ul class="divide-y divide-line">
                        <?php foreach ($checklist as $item): if ($item['doc'] === null && !$item['required']) { continue; } ?>
                            <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                <span class="flex items-center gap-2"><?= icon($item['doc'] !== null ? 'file-check' : 'file-x', 'size-4 ' . ($item['doc'] !== null ? 'text-emerald-600' : 'text-red-500')) ?><?= e($item['type']->label()) ?></span>
                                <span class="truncate text-xs text-muted"><?= $item['doc'] !== null ? e($item['doc']['original_name']) : 'Missing' ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                </section>

                <?php if ($kyc === KycStatus::Pending): ?>
                    <?= $this->component('alert', ['tone' => 'info', 'title' => 'Submitted — awaiting verification', 'message' => 'Submitted on ' . format_date((string) $customer['kyc_submitted_at'], 'd M Y, g:i a') . '. We will email you when it is verified. You can still correct details above.']) ?>
                <?php elseif ($kyc === KycStatus::Verified): ?>
                    <?= $this->component('alert', ['tone' => 'success', 'title' => 'KYC verified', 'message' => 'Your profile is verified. Nothing to submit.']) ?>
                <?php else: ?>
                    <form method="post" action="<?= e(url('portal.wizard.save', ['step' => 4])) ?>" class="card card-body space-y-5 sm:!p-8">
                        <?= csrf_field() ?>
                        <?= $this->component('checkbox', ['name' => 'declaration', 'label' => 'I declare that the information and documents provided are true and belong to me' . ($type === CustomerType::Institution ? ' / my institution' : '') . '.', 'help' => 'Providing false information may lead to cancellation of bookings.']) ?>
                        <div class="flex items-center justify-between gap-3">
                            <a href="<?= e(url('portal.wizard', ['step' => 3])) ?>" class="btn btn-ghost"><?= icon('arrow-left', 'size-4') ?> Back</a>
                            <button type="submit" class="btn btn-primary btn-lg" <?= $missing !== [] ? 'disabled' : '' ?>><?= icon('send', 'size-4') ?> <?= $kyc === KycStatus::Rejected ? 'Re-submit for verification' : 'Submit for verification' ?></button>
                        </div>
                    </form>
                <?php endif ?>
            </div>
        <?php endif ?>
    </div>

    <aside class="space-y-5 lg:sticky lg:top-28 lg:self-start">
        <div class="card card-body">
            <h2 class="flex items-center gap-2 text-sm font-bold"><?= icon('shield-check', 'size-4 text-brand-600') ?> Why we ask</h2>
            <p class="mt-2 text-sm text-muted">Commune is a Government of Kerala facility. KYC keeps the workspace safe and lets us issue GST invoices in your <?= $type === CustomerType::Individual ? 'name' : 'institution’s name' ?>.</p>
        </div>
        <div class="card card-body">
            <h2 class="flex items-center gap-2 text-sm font-bold"><?= icon('lock', 'size-4 text-brand-600') ?> Your data is protected</h2>
            <ul class="mt-2 space-y-2 text-sm text-muted">
                <li class="flex gap-2"><?= icon('check', 'mt-0.5 size-4 shrink-0 text-emerald-600') ?> Aadhaar encrypted at rest; only the last 4 digits shown.</li>
                <li class="flex gap-2"><?= icon('check', 'mt-0.5 size-4 shrink-0 text-emerald-600') ?> Documents stored privately, never public.</li>
                <li class="flex gap-2"><?= icon('check', 'mt-0.5 size-4 shrink-0 text-emerald-600') ?> Photos are re-saved to strip location data.</li>
            </ul>
        </div>
        <div class="card card-body">
            <h2 class="flex items-center gap-2 text-sm font-bold"><?= icon('circle-help', 'size-4 text-brand-600') ?> Need help?</h2>
            <p class="mt-2 text-sm text-muted">Your progress is saved after every step — come back any time. Or visit the front desk and we’ll do it with you.</p>
            <a href="<?= e(url('contact')) ?>" class="btn btn-outline btn-sm mt-4">Contact the centre</a>
        </div>
    </aside>
</div>
