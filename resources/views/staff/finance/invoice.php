<?php
/**
 * Invoice detail: snapshot, lines, receipts, credit notes and the credit-note form (full / partial).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $invoice
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $creditNotes
 * @var list<array<string, mixed>> $receipts
 * @var array{taxable: float, total: float, items: list<array<string, mixed>>} $remaining
 * @var array{reason: string, taxable: float, explain: string, note: string}|null $suggestion
 * @var array<string, string> $reasons
 * @var bool $canCredit
 * @var bool $canManage
 */
use App\Enums\CreditNoteReason;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\GstMath;
use App\Support\IndianStates;

$this->layout('layouts/staff', ['title' => 'Invoice ' . $invoice['invoice_no'], 'subtitle' => 'Issued ' . format_date((string) $invoice['invoice_date'], 'd M Y') . ($invoice['issued_by_name'] ? ' by ' . $invoice['issued_by_name'] : '') . ' · immutable — corrections only by credit note.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Invoices & receipts', url('staff.invoices.index', ['tab' => 'invoices'])], [$invoice['invoice_no']]]]);
$inter = (float) $invoice['igst'] > 0;
$cancelled = $invoice['status'] === 'cancelled';
$left = $remaining['taxable'];
$m = static fn ($v) => money($v, 2);
?>
<?php $this->start('actions') ?>
<a class="btn btn-brand" href="<?= e(FinanceDocuments::url('invoice', $invoice)) ?>" target="_blank" rel="noopener"><?= icon('file-down', 'size-4') ?>Download PDF</a>
<?php if ($canManage): ?>
    <form method="post" target="_blank" action="<?= e(url('staff.finance.documents.reprint', ['type' => 'invoice', 'id' => $invoice['id']])) ?>"><?= csrf_field() ?><button class="btn btn-outline" title="Re-generate with a DUPLICATE COPY watermark"><?= icon('printer', 'size-4') ?>Reprint</button></form>
<?php endif ?>
<?php $this->stop() ?>

<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="card card-body">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="eyebrow">Tax invoice · <?= $invoice['kind'] === 'rent' ? 'rent period' : 'advance' ?></p>
                    <h2 class="mt-1 font-mono text-2xl font-extrabold"><?= e($invoice['invoice_no']) ?></h2>
                    <p class="mt-1 text-sm text-muted"><?= e(format_date((string) $invoice['period_start']) . ' – ' . format_date((string) $invoice['period_end'])) ?> · booking <a class="font-mono font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $invoice['booking_no']])) ?>"><?= e($invoice['booking_no']) ?></a></p>
                </div>
                <div class="text-right">
                    <?= $this->component('badge', ['label' => $cancelled ? 'Credited in full' : ((float) $invoice['credited_total'] > 0 ? 'Part credited' : 'Issued'), 'tone' => $cancelled ? 'danger' : ((float) $invoice['credited_total'] > 0 ? 'warning' : 'success'), 'dot' => true]) ?>
                    <p class="mt-2 font-display text-3xl font-extrabold tabular-nums"><?= e($m($invoice['total'])) ?></p>
                    <?php if ((float) $invoice['credited_total'] > 0): ?><p class="text-sm text-muted">credited <?= e($m($invoice['credited_total'])) ?> · net <?= e($m((float) $invoice['total'] - (float) $invoice['credited_total'])) ?></p><?php endif ?>
                </div>
            </div>
            <dl class="mt-5 grid gap-4 rounded-2xl bg-surface p-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs font-semibold text-muted">Recipient</dt><dd class="font-semibold"><?= e($invoice['customer_name']) ?></dd><dd class="font-mono text-xs text-muted"><?= e($invoice['customer_unique_id'] ?? '') ?></dd></div>
                <div><dt class="text-xs font-semibold text-muted">GSTIN · PAN</dt><dd class="font-mono font-semibold"><?= e($invoice['customer_gstin'] ?: 'Unregistered (B2C)') ?></dd><dd class="font-mono text-xs text-muted"><?= e($invoice['customer_pan'] ?? '—') ?></dd></div>
                <div><dt class="text-xs font-semibold text-muted">Place of supply</dt><dd class="font-semibold"><?= e(IndianStates::name((string) $invoice['place_of_supply'])) ?> (<?= e($invoice['place_of_supply']) ?>)</dd><dd class="text-xs text-muted"><?= $inter ? 'Inter-state → IGST' : 'Intra-state → CGST + SGST' ?></dd></div>
            </dl>
        </section>

        <section class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>#</th><th>Description</th><th>SAC</th><th class="text-right">Qty</th><th class="text-right">Taxable</th><th class="text-right"><?= $inter ? 'IGST' : 'CGST + SGST' ?></th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="whitespace-normal"><div class="font-semibold"><?= e($it['description']) ?></div><div class="text-xs text-muted"><?= e($it['detail'] ?? '') ?></div></td>
                        <td class="font-mono text-xs"><?= e($it['sac']) ?></td>
                        <td class="text-right"><?= e(rtrim(rtrim(number_format((float) $it['qty'], 2), '0'), '.')) ?> <span class="text-xs text-muted"><?= e($it['unit'] ?? '') ?></span></td>
                        <td class="text-right tabular-nums"><?= e($m($it['taxable_value'])) ?></td>
                        <td class="text-right tabular-nums"><?= e($m((float) $it['cgst'] + (float) $it['sgst'] + (float) $it['igst'])) ?><div class="text-xs text-muted">@ <?= e(GstMath::rateLabel($it['gst_rate'])) ?></div></td>
                        <td class="text-right font-bold tabular-nums"><?= e($m($it['total'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
                <tfoot class="bg-surface text-sm font-bold">
                    <tr><td colspan="4" class="px-4 py-3 text-right">Totals<?= (float) $invoice['round_off'] !== 0.0 ? ' (round off ' . e($m($invoice['round_off'])) . ')' : '' ?></td><td class="px-4 py-3 text-right tabular-nums"><?= e($m($invoice['taxable_value'])) ?></td><td class="px-4 py-3 text-right tabular-nums"><?= e($m((float) $invoice['cgst'] + (float) $invoice['sgst'] + (float) $invoice['igst'])) ?></td><td class="px-4 py-3 text-right tabular-nums"><?= e($m($invoice['total'])) ?></td></tr>
                </tfoot>
            </table>
            <p class="border-t border-line px-4 py-3 text-sm"><span class="text-muted">In words:</span> <b><?= e($invoice['amount_words']) ?></b></p>
        </section>

        <section class="card card-body">
            <h2 class="text-lg font-bold">Credit notes</h2>
            <?php if ($creditNotes === []): ?>
                <p class="mt-3 text-sm text-muted">None — the invoice stands as issued.</p>
            <?php else: ?>
                <ul class="mt-3 divide-y divide-line rounded-2xl ring-1 ring-line">
                    <?php foreach ($creditNotes as $cn): $reason = CreditNoteReason::tryFrom((string) $cn['reason_code']); ?>
                        <li class="flex flex-wrap items-center gap-3 p-3.5">
                            <span class="grid size-9 place-items-center rounded-xl bg-accent-50 text-accent-600"><?= icon('file-minus', 'size-4') ?></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-bold"><?= e($cn['credit_note_no']) ?> <span class="font-sans text-xs font-normal text-muted">· <?= e(format_date((string) $cn['note_date'])) ?><?= $cn['issued_by_name'] ? ' · ' . e($cn['issued_by_name']) : '' ?></span></p>
                                <p class="text-sm"><?= $this->component('badge', ['label' => $reason?->label() ?? 'Other', 'tone' => $reason?->tone() ?? 'neutral']) ?> <span class="text-muted"><?= e($cn['reason']) ?></span></p>
                            </div>
                            <p class="font-bold tabular-nums">− <?= e($m($cn['total'])) ?></p>
                            <a class="btn btn-outline btn-sm" href="<?= e(FinanceDocuments::url('credit_note', $cn)) ?>" target="_blank" rel="noopener"><?= icon('file-down', 'size-4') ?>PDF</a>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </section>
    </div>

    <div class="space-y-6">
        <?php if ($canCredit && !$cancelled && $left > 0): ?>
            <section class="card card-body" id="credit-note" x-data="{ scope: <?= e(json_encode(old('scope', $suggestion !== null && $suggestion['taxable'] < $left ? 'partial' : 'full') === 'partial' ? 'partial' : 'full')) ?> }">
                <h2 class="text-lg font-bold">Issue a credit note</h2>
                <p class="mt-1 text-sm text-muted">Reverses taxable value and GST against this invoice. Up to <b class="text-ink"><?= e($m($left)) ?></b> taxable (<?= e($m($remaining['total'])) ?> incl. GST) is left to credit.</p>
                <?php if ($suggestion !== null): ?>
                    <div class="mt-4 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200"><?= icon('info', 'mr-1 inline size-4') ?><?= e($suggestion['explain']) ?></div>
                <?php endif ?>
                <form method="post" action="<?= e(url('staff.credit_notes.store', ['id' => $invoice['id']])) ?>" class="mt-4 space-y-4">
                    <?= csrf_field() ?>
                    <?= $this->component('select', ['name' => 'reason_code', 'label' => 'Reason', 'value' => $suggestion['reason'] ?? 'early_exit', 'options' => $reasons, 'required' => true]) ?>
                    <?= $this->component('textarea', ['name' => 'reason', 'label' => 'Explanation (printed on the credit note)', 'rows' => 3, 'required' => true, 'value' => $suggestion['note'] ?? '', 'placeholder' => 'e.g. Early exit on 15 Nov 2026 — unused period credited.']) ?>
                    <fieldset>
                        <legend class="label">Amount</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl p-3 text-sm font-semibold ring-1 ring-line" :class="scope === 'full' && 'bg-brand-50 ring-brand-600'"><input type="radio" name="scope" value="full" x-model="scope" class="accent-brand-600">Everything left</label>
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl p-3 text-sm font-semibold ring-1 ring-line" :class="scope === 'partial' && 'bg-brand-50 ring-brand-600'"><input type="radio" name="scope" value="partial" x-model="scope" class="accent-brand-600">Part</label>
                        </div>
                    </fieldset>
                    <div x-show="scope === 'partial'" x-cloak>
                        <?= $this->component('input', ['name' => 'taxable', 'label' => 'Taxable value to credit (₹)', 'type' => 'number', 'value' => $suggestion !== null ? number_format($suggestion['taxable'], 2, '.', '') : '', 'help' => 'GST is reversed at each line’s rate (' . ($inter ? 'IGST' : 'CGST + SGST') . '). Max ' . $m($left) . '.', 'attrs' => ['step' => '0.01', 'min' => '0.01', 'max' => number_format($left, 2, '.', '')]]) ?>
                    </div>
                    <button class="btn btn-brand w-full"><?= icon('file-minus', 'size-4') ?>Issue credit note</button>
                </form>
            </section>
        <?php endif ?>
        <section class="card card-body">
            <h2 class="text-base font-bold">Receipts for this booking</h2>
            <?php if ($receipts === []): ?><p class="mt-2 text-sm text-muted">None.</p><?php endif ?>
            <ul class="mt-2 space-y-2">
                <?php foreach ($receipts as $r): ?>
                    <li class="flex items-center justify-between gap-2 text-sm">
                        <a class="font-mono font-semibold text-brand-700 hover:underline" href="<?= e(FinanceDocuments::url('receipt', $r)) ?>" target="_blank" rel="noopener"><?= e($r['receipt_no']) ?></a>
                        <span class="text-muted"><?= $r['kind'] === 'deposit' ? 'Deposit' : 'Payment' ?></span>
                        <span class="font-bold tabular-nums"><?= e($m($r['amount'])) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
        <section class="card card-body text-sm">
            <h2 class="text-base font-bold">Delivery</h2>
            <p class="mt-2 text-muted"><?= $invoice['emailed_at'] ? 'Emailed to ' . e($invoice['customer_email'] ?? 'the visitor') . ' on ' . e(format_date((string) $invoice['emailed_at'], 'd M Y, g:i a')) . '.' : 'Not emailed (no email on file or emailing is off).' ?></p>
            <p class="mt-1 text-muted">Reprints: <?= (int) $invoice['print_count'] ?> · stored original <?= $invoice['pdf_path'] ? '<span class="font-mono text-xs">' . e($invoice['pdf_path']) . '</span>' : 'not generated yet' ?></p>
        </section>
    </div>
</div>
