<?php
/**
 * Finance settings: supplier, GST, numbering, bank, signatory, images, terms (FinanceSettings).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $values
 * @var array<string, string> $states
 * @var array<string, string> $next next number per sequence (current FY)
 * @var string $fy
 * @var array<string, string|null> $images data URIs of the current logo / signature / seal
 */
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\NumberSequence;

$this->layout('layouts/staff', ['title' => 'Finance settings', 'subtitle' => 'Printed on every GST invoice, receipt and credit note. Issued documents keep the details they were issued with.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Finance'], ['Settings']]]);
$f = static fn (string $key, array $extra = []) => ['name' => $key, 'label' => FinanceSettings::FIELDS[$key][0], 'value' => $values[$key] ?? ''] + $extra;
?>
<form method="post" action="<?= e(url('staff.finance.settings.update')) ?>" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-3">
    <?= csrf_field() ?><?= method_field('PUT') ?>
    <div class="space-y-6 xl:col-span-2">
        <section class="card card-body">
            <h2 class="text-lg font-bold">Supplier</h2>
            <p class="text-sm text-muted">The legal entity issuing the documents.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <?= $this->component('input', $f('supplier_legal_name', ['required' => true, 'class' => 'sm:col-span-2'])) ?>
                <?= $this->component('input', $f('supplier_trade_name', ['class' => 'sm:col-span-2'])) ?>
                <?= $this->component('textarea', $f('supplier_address', ['rows' => 2, 'required' => true, 'class' => 'sm:col-span-2'])) ?>
                <?= $this->component('input', $f('org_gstin', ['placeholder' => '32AAAGK1234A1Z5', 'help' => 'Leave empty until registration — documents print “Registration pending”.', 'attrs' => ['maxlength' => 15, 'class' => 'uppercase']])) ?>
                <?= $this->component('input', $f('supplier_pan', ['placeholder' => 'AAAGK1234A', 'attrs' => ['maxlength' => 10]])) ?>
                <?= $this->component('select', $f('home_state_code', ['options' => array_combine(array_keys($states), array_map(static fn ($c, $n) => $n . ' (' . $c . ')', array_keys($states), $states)), 'required' => true, 'help' => 'Customers from another state are charged IGST.'])) ?>
                <?= $this->component('input', $f('supplier_email', ['type' => 'email'])) ?>
                <?= $this->component('input', $f('supplier_phone')) ?>
            </div>
        </section>

        <section class="card card-body">
            <h2 class="text-lg font-bold">GST &amp; numbering</h2>
            <p class="text-sm text-muted">Numbers restart every financial year (April–March) and are allocated under a database lock — no gaps.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <?= $this->component('input', $f('sac_code', ['required' => true, 'help' => '997212 — rental of non-residential property (to be confirmed by Finance).'])) ?>
                <?= $this->component('input', $f('gst_rate', ['type' => 'number', 'required' => true, 'help' => 'Default for new rates; CGST/SGST = half each.', 'attrs' => ['step' => '0.01', 'min' => '0', 'max' => '28']])) ?>
                <?php foreach (['invoice_prefix' => NumberSequence::INVOICE, 'receipt_prefix' => NumberSequence::RECEIPT, 'credit_note_prefix' => NumberSequence::CREDIT_NOTE, 'refund_voucher_prefix' => NumberSequence::REFUND_VOUCHER] as $key => $seq): ?>
                    <?= $this->component('input', $f($key, ['required' => true, 'help' => 'Next in FY ' . $fy . ': ' . $next[$seq]])) ?>
                <?php endforeach ?>
            </div>
        </section>

        <section class="card card-body">
            <h2 class="text-lg font-bold">Bank details</h2>
            <p class="text-sm text-muted">Printed on invoices for NEFT / UPI payments.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <?php foreach (['bank_account_name', 'bank_name', 'bank_branch', 'bank_account_no', 'bank_ifsc', 'bank_upi'] as $key): ?>
                    <?= $this->component('input', $f($key)) ?>
                <?php endforeach ?>
            </div>
        </section>

        <section class="card card-body">
            <h2 class="text-lg font-bold">Terms &amp; footer</h2>
            <div class="mt-4 grid gap-4">
                <?= $this->component('textarea', $f('invoice_terms', ['rows' => 3])) ?>
                <?= $this->component('input', $f('invoice_footer')) ?>
                <?= $this->component('checkbox', ['name' => 'finance_email_documents', 'label' => 'Email invoices, receipts and credit notes to the visitor when issued (PDF attached)', 'checked' => (bool) $values['finance_email_documents']]) ?>
            </div>
        </section>
    </div>

    <div class="space-y-6">
        <section class="card card-body">
            <h2 class="text-lg font-bold">Authorised signatory</h2>
            <div class="mt-4 grid gap-4">
                <?= $this->component('input', $f('signatory_name')) ?>
                <?= $this->component('input', $f('signatory_designation')) ?>
            </div>
        </section>
        <section class="card card-body">
            <h2 class="text-lg font-bold">Logo, signature &amp; seal</h2>
            <p class="text-sm text-muted">PNG with a transparent background works best. Stored privately; embedded into PDFs.</p>
            <div class="mt-4 space-y-5">
                <?php foreach (FinanceSettings::IMAGES as $key => $label): ?>
                    <div>
                        <label class="label" for="f-<?= e($key) ?>"><?= e($label) ?></label>
                        <?php if (!empty($images[$key])): ?>
                            <div class="mb-2 flex items-center gap-3 rounded-2xl bg-surface p-3">
                                <img src="<?= e($images[$key]) ?>" alt="Current <?= e(strtolower($label)) ?>" class="max-h-14 max-w-32 object-contain">
                                <label class="ml-auto inline-flex items-center gap-2 text-xs font-semibold text-red-700"><input type="checkbox" name="remove[]" value="<?= e($key) ?>" class="accent-red-600">Remove</label>
                            </div>
                        <?php endif ?>
                        <input id="f-<?= e($key) ?>" type="file" name="<?= e($key) ?>" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm file:mr-3 file:rounded-full file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <?php if (errors($key)): ?><p class="mt-1 text-sm text-red-600"><?= e((string) errors($key)) ?></p><?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        </section>
        <button class="btn btn-brand btn-lg w-full"><?= icon('save', 'size-5') ?>Save settings</button>
    </div>
</form>
