<?php
/**
 * Assisted registration / edit (spec 4.2) — the same field partials as the online wizard, as one long form.
 *
 * @var App\Core\Template $this
 * @var string $mode create|edit
 * @var App\Enums\CustomerType $type
 * @var array<string, mixed> $customer
 * @var array<string, mixed>|null $signatory
 * @var list<array<string, mixed>> $checklist
 * @var list<array<string, mixed>> $duplicates   server-side matches (flashed after a submit)
 * @var bool $canVerify
 */
use App\Enums\CustomerType;
use App\Models\Customer;

$create = $mode === 'create';
$this->layout('layouts/staff', ['breadcrumb' => array_values(array_filter([
    ['Dashboard', url('staff.dashboard')],
    ['Visitors', url('staff.visitors.index')],
    $create ? null : [(string) $customer['name'], url('staff.visitors.show', ['ref' => Customer::ref($customer)])],
    [$create ? 'New visitor' : 'Edit'],
]))]);
$individual = $type === CustomerType::Individual;
$action = $create ? url('staff.visitors.store') : url('staff.visitors.update', ['ref' => Customer::ref($customer)]);
$foreign = !$create && Customer::isForeign($customer);
$sections = $create ? ['Basic details', 'Identity & KYC', 'Documents', 'Finish'] : ['Basic details', 'Identity & KYC'];
?>
<?php if ($create): ?>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <span class="text-sm font-semibold text-muted">Registering</span>
        <?= $this->component('chips', ['active' => $type->value, 'label' => 'Visitor type', 'items' => [
            ['value' => 'individual', 'label' => 'Individual', 'icon' => 'user-round', 'href' => url('staff.visitors.create', ['type' => 'individual'])],
            ['value' => 'institution', 'label' => 'Institution', 'icon' => 'building-2', 'href' => url('staff.visitors.create', ['type' => 'institution'])],
        ]]) ?>
    </div>
<?php endif ?>

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate autocomplete="off"
      x-data="duplicateCheck('<?= e(url('staff.visitors.duplicates')) ?>', <?= (int) ($customer['id'] ?? 0) ?>, '<?= $foreign ? 'foreign' : 'indian' ?>')" @change="lookup($event)"
      class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
    <?= csrf_field() ?>
    <?php if (!$create): ?><?= method_field('PUT') ?><?php endif ?>
    <input type="hidden" name="type" value="<?= e($type->value) ?>">

    <div class="min-w-0 space-y-6">
        <section class="card card-body sm:!p-7" id="basic">
            <h2 class="flex items-center gap-3 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-brand-600 text-sm text-white">1</span> <?= $individual ? 'Basic details' : 'Institution details' ?></h2>
            <div class="mt-6"><?= $this->partial('partials/visitor/basic-fields', ['emailLocked' => false]) ?></div>
        </section>

        <section class="card card-body sm:!p-7" id="identity">
            <h2 class="flex items-center gap-3 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-brand-600 text-sm text-white">2</span> Identity &amp; KYC</h2>
            <p class="mt-1 text-sm text-muted">Checked as you type. Aadhaar is encrypted on save; only the last 4 digits are shown afterwards.</p>
            <div class="mt-6"><?= $this->partial('partials/visitor/identity-fields') ?></div>
        </section>

        <?php if ($create): ?>
            <section class="card card-body sm:!p-7" id="documents">
                <h2 class="flex items-center gap-3 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-brand-600 text-sm text-white">3</span> Documents</h2>
                <p class="mt-1 text-sm text-muted">Scan, upload or capture with the desk webcam. You can also add documents later from the visitor’s page.</p>
                <?php if (errors() !== []): ?>
                    <?= $this->component('alert', ['tone' => 'warning', 'class' => 'mt-4', 'message' => 'For security, files and Aadhaar numbers are not kept after a failed submit — please attach / enter them again.']) ?>
                <?php endif ?>
                <div class="mt-6"><?= $this->partial('partials/visitor/documents', ['context' => 'staff', 'mode' => 'inline', 'webcam' => true]) ?></div>
            </section>

            <section class="card card-body sm:!p-7" id="finish">
                <h2 class="flex items-center gap-3 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-brand-600 text-sm text-white">4</span> Finish</h2>
                <div class="mt-5 space-y-4">
                    <?= $this->component('checkbox', ['name' => 'send_invite', 'label' => 'Send portal invite', 'help' => 'Emails a set-password link so the visitor can use the online portal later (bookings, invoices).', 'checked' => true]) ?>
                    <?php if ($canVerify): ?>
                        <?= $this->component('checkbox', ['name' => 'mark_verified', 'label' => 'Mark KYC as verified now', 'help' => 'Only if you have checked the original documents at the desk. All required documents must be attached.']) ?>
                    <?php endif ?>
                </div>
            </section>
        <?php endif ?>
    </div>

    <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
        <nav class="card card-body hidden xl:block" aria-label="Form sections">
            <p class="text-xs font-bold tracking-[0.14em] text-muted uppercase">Sections</p>
            <ol class="mt-3 space-y-1 text-sm">
                <?php foreach ($sections as $i => $label): ?>
                    <li><a href="#<?= ['basic', 'identity', 'documents', 'finish'][$i] ?>" class="flex items-center gap-2 rounded-lg px-2 py-1.5 font-medium text-ink/80 hover:bg-surface"><span class="grid size-6 place-items-center rounded-full bg-surface-2 text-xs font-bold"><?= $i + 1 ?></span><?= e($label) ?></a></li>
                <?php endforeach ?>
            </ol>
        </nav>

        <?php if ($duplicates !== []): ?>
            <div class="card border-amber-300 bg-amber-50 p-5 ring-1 ring-amber-200">
                <p class="flex items-center gap-2 font-bold text-amber-900"><?= icon('user-search', 'size-5') ?> Possible existing visitor</p>
                <p class="mt-1 text-sm text-amber-900/80">These records share details with this registration. Open them to check before creating a duplicate.</p>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($duplicates as $m): $mc = $m['customer']; ?>
                        <li><a href="<?= e(url('staff.visitors.show', ['ref' => Customer::ref($mc)])) ?>" target="_blank" class="block rounded-xl bg-white p-3 text-sm shadow-xs hover:ring-2 hover:ring-amber-300">
                            <span class="block font-semibold"><?= e($mc['name']) ?></span>
                            <span class="block font-mono text-xs text-muted"><?= e($mc['unique_id'] ?? 'Not submitted') ?></span>
                            <span class="mt-1 flex flex-wrap gap-1"><?php foreach ($m['labels'] as $l): ?><?= $this->component('badge', ['label' => 'Same ' . $l, 'tone' => 'warning']) ?><?php endforeach ?></span>
                        </a></li>
                    <?php endforeach ?>
                </ul>
                <div class="mt-4 border-t border-amber-200 pt-4">
                    <?= $this->component('checkbox', ['name' => 'confirm_duplicates', 'label' => 'I checked — this is a different visitor', 'help' => 'Register anyway.']) ?>
                </div>
            </div>
        <?php endif ?>

        <div x-cloak x-show="matches.length" x-transition class="card border-amber-300 bg-amber-50 p-5 ring-1 ring-amber-200" aria-live="polite">
            <p class="flex items-center gap-2 font-bold text-amber-900"><?= icon('user-search', 'size-5') ?> Possible existing visitor</p>
            <p class="mt-1 text-sm text-amber-900/80">Found while you typed:</p>
            <ul class="mt-3 space-y-2">
                <template x-for="m in matches" :key="m.url">
                    <li><a :href="m.url" target="_blank" class="block rounded-xl bg-white p-3 text-sm shadow-xs hover:ring-2 hover:ring-amber-300">
                        <span class="block font-semibold" x-text="m.name"></span>
                        <span class="block font-mono text-xs text-muted" x-text="(m.unique_id || 'Not submitted') + ' · ' + m.type + ' · ' + m.kyc"></span>
                        <span class="mt-1 flex flex-wrap gap-1"><template x-for="f in m.fields"><span class="badge badge-warning" x-text="'Same ' + f"></span></template></span>
                    </a></li>
                </template>
            </ul>
        </div>

        <div class="card card-body space-y-3">
            <button type="submit" class="btn btn-primary btn-lg w-full"><?= icon($create ? 'user-plus' : 'check', 'size-4') ?> <?= $create ? 'Register visitor' : 'Save changes' ?></button>
            <a href="<?= e($create ? url('staff.visitors.index') : url('staff.visitors.show', ['ref' => Customer::ref($customer)])) ?>" class="btn btn-ghost w-full">Cancel</a>
            <?php if ($create): ?><p class="text-center text-xs text-muted">A Unique Visitor ID is issued on save.</p><?php endif ?>
        </div>
    </aside>
</form>
