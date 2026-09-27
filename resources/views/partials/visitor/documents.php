<?php
/**
 * KYC document checklist with upload / replace / delete, drag & drop, phone camera and webcam capture.
 * Shared by the portal wizard (step 3), /my/documents, the staff visitor page and the staff create form.
 *
 * @var App\Core\Template $this
 * @var list<array{type: App\Enums\DocumentType, required: bool, hint: string, doc: array<string, mixed>|null}> $checklist
 * @var string $context    portal | staff
 * @var string|null $mode  forms (default: one upload form per document) | inline (file inputs inside a surrounding form)
 * @var string|null $ref   staff: customer ref for the upload / delete URLs
 * @var bool|null $locked  documents can no longer be changed (KYC verified)
 * @var bool|null $webcam  offer "Use webcam" (staff desks)
 * @var bool|null $canView staff: may open documents
 * @var string|null $gridClass  override the list grid columns (default "lg:grid-cols-2")
 */
use App\Enums\DocumentType;
use App\Services\Kyc\DocumentStore;

$mode ??= 'forms';
$locked = !empty($locked);
$webcam = !empty($webcam);
$canView = $canView ?? true;
$accept = DocumentStore::acceptAttribute();
$staff = $context === 'staff';
$failedType = (string) old('doc_type', '');
?>
<ul class="<?= e('grid gap-4 ' . ($gridClass ?? 'lg:grid-cols-2')) ?>" role="list">
    <?php foreach ($checklist as $item):
        /** @var DocumentType $t */
        $t = $item['type'];
        $doc = $item['doc'];
        $field = $mode === 'inline' ? 'doc_' . $t->value : 'file';
        $err = $mode === 'inline' ? errors($field) : ($failedType === $t->value ? errors('file') : null);
        $fileUrl = $doc !== null ? ($staff ? url('staff.documents.file', ['id' => $doc['id']]) : url('portal.documents.file', ['id' => $doc['id']])) : null;
        $show = match ($t) {
            DocumentType::Aadhaar => "nat !== 'foreign'",
            DocumentType::Passport => "nat === 'foreign'",
            default => null,
        };
    ?>
    <li class="card flex flex-col p-4 sm:p-5" x-data="docUpload()" <?= $mode === 'inline' && $show !== null ? 'x-show="' . e($show) . '"' : '' ?>>
        <div class="flex items-start gap-3">
            <span class="<?= e(class_names('grid size-10 shrink-0 place-items-center rounded-xl', $doc !== null ? 'bg-emerald-50 text-emerald-600' : ($item['required'] ? 'bg-accent-50 text-accent-600' : 'bg-surface text-muted'))) ?>">
                <?= icon($doc !== null ? 'file-check' : 'file-up', 'size-5') ?>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-bold"><?= e($t->label()) ?></h3>
                    <?php if ($doc !== null && $doc['verified_at'] !== null): ?>
                        <?= $this->component('badge', ['label' => 'Verified', 'tone' => 'success', 'icon' => 'badge-check']) ?>
                    <?php elseif ($doc !== null): ?>
                        <?= $this->component('badge', ['label' => 'Uploaded', 'tone' => 'info']) ?>
                    <?php elseif ($item['required']): ?>
                        <?= $this->component('badge', ['label' => 'Required', 'tone' => 'accent']) ?>
                    <?php else: ?>
                        <?= $this->component('badge', ['label' => 'Optional', 'tone' => 'neutral']) ?>
                    <?php endif ?>
                </div>
                <p class="mt-0.5 text-xs text-muted"><?= e($item['hint']) ?></p>
            </div>
        </div>

        <?php if ($doc !== null): ?>
            <div class="mt-4 flex items-center gap-3 rounded-xl border border-line bg-surface/60 p-2.5">
                <?php if ($canView && DocumentStore::isImage((string) $doc['mime'])): ?>
                    <img src="<?= e($fileUrl) ?>" alt="" loading="lazy" class="size-12 shrink-0 rounded-lg border border-line bg-white object-cover">
                <?php else: ?>
                    <span class="grid size-12 shrink-0 place-items-center rounded-lg border border-line bg-white text-red-600"><?= icon('file-text', 'size-6') ?></span>
                <?php endif ?>
                <div class="min-w-0 flex-1 text-xs">
                    <p class="truncate font-semibold text-ink"><?= e($doc['original_name'] ?? $t->label()) ?></p>
                    <p class="text-muted"><?= e(format_bytes($doc['size'])) ?> · <?= e(format_date($doc['created_at'], 'd M Y, g:i a')) ?></p>
                </div>
                <?php if ($canView): ?>
                    <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" title="Open in a new tab"><?= icon('eye', 'size-4') ?><span class="hidden sm:inline">View</span></a>
                <?php endif ?>
                <?php if (!$locked && $mode === 'forms'): ?>
                    <form method="post" action="<?= e($staff ? url('staff.visitors.documents.destroy', ['ref' => (string) $ref, 'id' => $doc['id']]) : url('portal.documents.destroy', ['id' => $doc['id']])) ?>"
                          x-data @submit="if (!confirm('Remove this document?')) $event.preventDefault()">
                        <?= csrf_field() ?><?= method_field('DELETE') ?>
                        <button type="submit" class="btn btn-ghost btn-sm !text-red-600 hover:!bg-red-50" title="Delete"><?= icon('trash-2', 'size-4') ?><span class="sr-only">Delete</span></button>
                    </form>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?php if (!$locked): ?>
            <?php if ($mode === 'forms'): ?><form method="post" enctype="multipart/form-data" action="<?= e($staff ? url('staff.visitors.documents.store', ['ref' => (string) $ref]) : url('portal.documents.store')) ?>" class="mt-4"><?= csrf_field() ?><input type="hidden" name="doc_type" value="<?= e($t->value) ?>"><?php else: ?><div class="mt-4"><?php endif ?>
                <input x-ref="input" type="file" name="<?= e($field) ?>" accept="<?= e($accept) ?>" class="sr-only" id="up-<?= e($field . '-' . $t->value) ?>" @change="pick($event)">
                <input x-ref="cam" type="file" accept="image/*" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true" @change="$event.target.files[0] && use($event.target.files[0], true)">

                <!-- Drop zone -->
                <label for="up-<?= e($field . '-' . $t->value) ?>" x-show="!file && !camera"
                       class="<?= e(class_names('flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 text-center transition', $doc !== null ? 'py-3' : 'py-5')) ?>"
                       :class="dragging ? 'border-brand-600 bg-brand-50' : '<?= $err !== null ? 'border-red-300 bg-red-50/40' : 'border-line hover:border-brand-400 hover:bg-surface/60' ?>'"
                       @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="drop($event)">
                    <span class="text-brand-600"><?= icon('upload', 'size-5') ?></span>
                    <span class="text-sm font-semibold"><span class="hidden sm:inline">Drag &amp; drop, or </span><span class="text-brand-600 underline decoration-brand-300 underline-offset-2">browse</span><?= $doc !== null ? ' to replace' : '' ?></span>
                    <?php if ($doc === null): ?><span class="text-xs text-muted">PDF, JPG, PNG or WebP · max 5 MB</span><?php endif ?>
                </label>
                <div x-show="!file && !camera" class="mt-2 flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline btn-sm sm:hidden" @click="$refs.cam.click()"><?= icon('camera', 'size-4') ?> Take photo</button>
                    <?php if ($webcam): ?>
                        <button type="button" class="btn btn-outline btn-sm" x-show="hasWebcam" @click="startCamera()"><?= icon('video', 'size-4') ?> Use webcam</button>
                    <?php endif ?>
                </div>

                <!-- Webcam -->
                <div x-cloak x-show="camera" class="overflow-hidden rounded-xl border border-line bg-brand-950">
                    <video x-ref="video" playsinline muted class="aspect-video w-full object-cover"></video>
                    <div class="flex justify-between gap-2 p-2">
                        <button type="button" class="btn btn-ghost btn-sm !text-white hover:!bg-white/10" @click="stopCamera()">Cancel</button>
                        <button type="button" class="btn btn-primary btn-sm" @click="snap()"><?= icon('camera', 'size-4') ?> Capture</button>
                    </div>
                </div>

                <!-- Preview -->
                <div x-cloak x-show="file" class="flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50/50 p-2.5">
                    <template x-if="preview"><img :src="preview" alt="Preview" class="size-14 shrink-0 rounded-lg border border-line bg-white object-cover"></template>
                    <template x-if="!preview"><span class="grid size-14 shrink-0 place-items-center rounded-lg border border-line bg-white text-red-600"><?= icon('file-text', 'size-6') ?></span></template>
                    <div class="min-w-0 flex-1 text-xs"><p class="truncate font-semibold" x-text="name"></p><p class="text-muted" x-text="size"></p></div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="reset()" title="Remove"><?= icon('x', 'size-4') ?><span class="sr-only">Remove</span></button>
                    <?php if ($mode === 'forms'): ?>
                        <button type="submit" class="btn btn-brand btn-sm"><?= icon('upload', 'size-4') ?> Upload</button>
                    <?php endif ?>
                </div>
                <p x-cloak x-show="error" x-text="error" class="error-text"></p>
                <?php if ($err !== null): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e($err) ?></p><?php endif ?>
            <?= $mode === 'forms' ? '</form>' : '</div>' ?>
        <?php endif ?>
    </li>
    <?php endforeach ?>
</ul>
