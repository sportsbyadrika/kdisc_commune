<?php
/**
 * KYC review: document viewer (image / PDF inline) side by side with the submitted data, then approve / reject.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var App\Enums\CustomerType $type
 * @var App\Enums\KycStatus $kyc
 * @var array<string, mixed>|null $signatory
 * @var list<array<string, mixed>> $documents
 * @var list<array<string, mixed>> $checklist
 * @var array<int, list<string>> $missing
 * @var int|null $nextId
 */
use App\Enums\CustomerSubCategory;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Services\Kyc\DocumentStore;

$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['KYC verification', url('staff.kyc.index')], [(string) $customer['name']]]]);
$sub = CustomerSubCategory::tryFrom((string) ($customer['sub_category'] ?? ''));
$docs = array_map(static fn (array $d) => [
    'id' => (int) $d['id'],
    'label' => DocumentType::tryFrom((string) $d['doc_type'])?->label() ?? (string) $d['doc_type'],
    'url' => url('staff.documents.file', ['id' => $d['id']]),
    'download' => url('staff.documents.file', ['id' => $d['id'], 'download' => 1]),
    'image' => DocumentStore::isImage((string) $d['mime']),
    'name' => (string) ($d['original_name'] ?? ''),
    'meta' => format_bytes($d['size']) . ' · ' . format_date((string) $d['created_at'], 'd M Y'),
], $documents);
$missingDocs = $missing[3] ?? [];
$missingData = array_merge($missing[1] ?? [], $missing[2] ?? []);
?>
<div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <?= $this->component('badge', ['label' => $kyc->label(), 'tone' => $kyc->tone(), 'dot' => true]) ?>
            <?= $this->component('badge', ['label' => $type->label() . ($sub !== null ? ' · ' . $sub->label() : ''), 'tone' => $type->tone()]) ?>
            <?php if ($customer['registered_via'] === 'reception'): ?><?= $this->component('badge', ['label' => 'Front desk', 'tone' => 'neutral', 'icon' => 'user-check']) ?><?php endif ?>
        </div>
        <h1 class="mt-3 truncate text-3xl font-extrabold"><?= e($customer['name']) ?></h1>
        <p class="mt-1 font-mono text-sm text-muted"><?= e($customer['unique_id'] ?? '') ?> · submitted <?= e(format_date($customer['kyc_submitted_at'] ?? null, 'd M Y, g:i a')) ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('staff.visitors.show', ['ref' => Customer::ref($customer)])) ?>" class="btn btn-outline"><?= icon('square-user', 'size-4') ?> Visitor record</a>
        <?php if ($nextId !== null): ?><a href="<?= e(url('staff.kyc.show', ['id' => $nextId])) ?>" class="btn btn-ghost">Skip to next <?= icon('arrow-right', 'size-4') ?></a><?php endif ?>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
    <!-- Document viewer -->
    <section class="card overflow-hidden xl:sticky xl:top-24 xl:self-start" x-data="{ i: 0, docs: <?= e(json_encode($docs)) ?>, get d() { return this.docs[this.i] } }">
        <?php if ($docs === []): ?>
            <div class="p-6"><?= $this->component('empty', ['icon' => 'file-x', 'title' => 'No documents uploaded', 'text' => 'Reject with a note asking the visitor to upload their proofs.']) ?></div>
        <?php else: ?>
            <div class="flex gap-1 overflow-x-auto border-b border-line bg-surface/70 p-2" role="tablist" aria-label="Documents">
                <template x-for="(doc, n) in docs" :key="doc.id">
                    <button type="button" role="tab" class="shrink-0 rounded-xl px-3 py-2 text-xs font-semibold transition" :aria-selected="(i === n).toString()"
                            :class="i === n ? 'bg-white text-ink shadow-sm ring-1 ring-line' : 'text-muted hover:bg-white/70 hover:text-ink'" @click="i = n" x-text="doc.label"></button>
                </template>
            </div>
            <div class="relative bg-brand-950/95">
                <template x-if="d.image">
                    <a :href="d.url" target="_blank" rel="noopener" class="block" title="Open full size">
                        <img :src="d.url" :alt="d.label" class="mx-auto max-h-[70vh] min-h-[320px] w-full object-contain">
                    </a>
                </template>
                <template x-if="!d.image">
                    <iframe :src="d.url" :title="d.label" class="h-[70vh] min-h-[420px] w-full bg-white"></iframe>
                </template>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-xs">
                <div class="min-w-0"><p class="truncate font-semibold" x-text="d.name"></p><p class="text-muted" x-text="d.meta"></p></div>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" @click="i = (i - 1 + docs.length) % docs.length" aria-label="Previous document"><?= icon('chevron-left', 'size-4') ?></button>
                    <span class="self-center tabular-nums text-muted" x-text="(i + 1) + ' / ' + docs.length"></span>
                    <button type="button" class="btn btn-ghost btn-sm" @click="i = (i + 1) % docs.length" aria-label="Next document"><?= icon('chevron-right', 'size-4') ?></button>
                    <a :href="d.url" target="_blank" rel="noopener" class="btn btn-outline btn-sm"><?= icon('external-link', 'size-3.5') ?> Open</a>
                    <a :href="d.download" class="btn btn-outline btn-sm"><?= icon('download', 'size-3.5') ?></a>
                </div>
            </div>
        <?php endif ?>
    </section>

    <!-- Data + decision -->
    <div class="min-w-0 space-y-6">
        <?php if ($missingDocs !== [] || $missingData !== []): ?>
            <?php $this->begin('alert', ['tone' => 'warning', 'title' => 'Incomplete submission']) ?>
                <ul class="mt-1 list-disc pl-5"><?php foreach (array_merge($missingData, $missingDocs) as $m): ?><li><?= e($m) ?></li><?php endforeach ?></ul>
            <?= $this->end() ?>
        <?php endif ?>

        <?php if ($kyc === KycStatus::Pending): ?>
            <section class="card card-body space-y-5" x-data="{ rejecting: <?= errors('reason') !== null ? 'true' : 'false' ?> }">
                <h2 class="text-base font-bold">Decision</h2>
                <form method="post" action="<?= e(url('staff.kyc.approve', ['id' => $customer['id']])) ?>" x-show="!rejecting" class="space-y-3">
                    <?= csrf_field() ?>
                    <?= $this->component('textarea', ['name' => 'remarks', 'label' => 'Note (optional)', 'rows' => 2, 'placeholder' => 'e.g. Originals seen at desk']) ?>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-brand flex-1 !bg-emerald-600 hover:!bg-emerald-700"><?= icon('badge-check', 'size-4') ?> Approve KYC</button>
                        <button type="button" class="btn btn-outline flex-1 !text-red-600" @click="rejecting = true; $nextTick(() => $refs.reason.focus())"><?= icon('circle-x', 'size-4') ?> Reject…</button>
                    </div>
                </form>
                <form method="post" action="<?= e(url('staff.kyc.reject', ['id' => $customer['id']])) ?>" x-cloak x-show="rejecting" class="space-y-3">
                    <?= csrf_field() ?>
                    <div>
                        <label for="f-reason" class="label">Reason — emailed to the visitor <span class="text-accent-500">*</span></label>
                        <textarea id="f-reason" x-ref="reason" name="reason" rows="3" class="<?= e(class_names('input', ['input-error' => errors('reason') !== null])) ?>" placeholder="e.g. The PAN card image is blurred — please upload a clearer scan."><?= e(old('reason')) ?></textarea>
                        <?php if (errors('reason')): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e(errors('reason')) ?></p><?php endif ?>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <?php foreach (['The document image is unclear — please upload a clearer scan.', 'The name on the ID does not match the profile.', 'A required document is missing.', 'The GST certificate does not match the GSTIN given.'] as $preset): ?>
                                <button type="button" class="chip !px-2.5 !py-1 !text-xs" @click="$refs.reason.value = <?= e(json_encode($preset)) ?>"><?= e($preset) ?></button>
                            <?php endforeach ?>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-ghost" @click="rejecting = false">Cancel</button>
                        <button type="submit" class="btn btn-danger flex-1"><?= icon('send', 'size-4') ?> Reject &amp; email visitor</button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <?= $this->component('alert', ['tone' => $kyc === KycStatus::Verified ? 'success' : 'info', 'message' => 'This profile is ' . strtolower($kyc->label()) . '.' . (!empty($customer['kyc_remarks']) ? ' Note: ' . $customer['kyc_remarks'] : '')]) ?>
        <?php endif ?>
        <section class="card card-body">
            <h2 class="text-sm font-bold">Checklist</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <?php foreach ($checklist as $item): if (!$item['required'] && $item['doc'] === null) { continue; } ?>
                    <li class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2"><?= icon($item['doc'] !== null ? 'circle-check' : 'circle-x', 'size-4 ' . ($item['doc'] !== null ? 'text-emerald-600' : 'text-red-500')) ?><?= e($item['type']->label()) ?></span>
                        <span class="text-xs text-muted"><?= $item['required'] ? 'Required' : 'Optional' ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>

        <?= $this->partial('partials/visitor/summary', ['editBase' => null]) ?>

    </div>
</div>
