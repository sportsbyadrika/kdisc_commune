<?php
/**
 * Finance documents + allotment letter of one booking (staff booking page and portal booking page).
 *
 * @var App\Core\Template $this
 * @var array<string, list<array<string, mixed>>> $documents FinanceDocuments::forBooking()
 * @var string|null $allotmentUrl
 * @var bool|null $portal  visitor links (/my/invoices/…) instead of staff links
 * @var list<array<string, mixed>>|null $pendingInvoices staff: queued (verified, not invoiced) items
 */
use App\Services\Finance\FinanceDocuments;

$portal = !empty($portal);
$labels = ['invoice' => ['Tax invoice', 'receipt-indian-rupee', 'invoice_no', 'total'], 'receipt' => ['Receipt', 'receipt', 'receipt_no', 'amount'], 'credit_note' => ['Credit note', 'file-minus', 'credit_note_no', 'total'], 'deposit_refund' => ['Deposit refund', 'banknote', 'voucher_no', 'refund_amount']];
$any = array_sum(array_map('count', $documents)) > 0;
?>
<?php if ($allotmentUrl !== null): ?>
    <a href="<?= e($allotmentUrl) ?>" target="_blank" rel="noopener" class="mb-3 flex items-center gap-3 rounded-2xl bg-brand-50 p-3.5 ring-1 ring-brand-100 transition hover:bg-brand-100">
        <span class="grid size-9 place-items-center rounded-xl bg-white text-brand-700"><?= icon('scroll-text', 'size-4') ?></span>
        <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-brand-900">Allotment letter</span><span class="block text-xs text-brand-800/70">Booking details, seat map &amp; conditions (PDF)</span></span>
        <?= icon('file-down', 'size-4 text-brand-700') ?>
    </a>
<?php endif ?>
<?php if (!$any): ?>
    <p class="rounded-2xl border-2 border-dashed border-line p-4 text-center text-sm text-muted"><?= $portal ? 'Receipts and GST invoices appear here once our finance team verifies your payment.' : 'No finance documents yet — receipts are issued when Finance verifies a payment.' ?></p>
<?php else: ?>
    <ul class="divide-y divide-line rounded-2xl ring-1 ring-line">
        <?php foreach ($labels as $type => [$label, $ic, $noKey, $amountKey]): ?>
            <?php foreach ($documents[$type] ?? [] as $d): ?>
                <li class="flex items-center gap-3 p-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-surface text-ink/70"><?= icon($ic, 'size-4') ?></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-mono text-xs font-bold"><?= e($d[$noKey]) ?></p>
                        <p class="text-xs text-muted"><?= e($type === 'receipt' && $d['kind'] === 'deposit' ? 'Deposit receipt' : $label) ?> · <?= e(format_date((string) ($d['invoice_date'] ?? $d['receipt_date'] ?? $d['note_date'] ?? $d['voucher_date']), 'd M Y')) ?><?= $type === 'invoice' && $d['status'] === 'cancelled' ? ' · credited' : '' ?></p>
                    </div>
                    <span class="text-sm font-bold tabular-nums"><?= $type === 'credit_note' ? '− ' : '' ?><?= e(money($d[$amountKey], 2)) ?></span>
                    <a class="btn btn-ghost btn-sm" href="<?= e(FinanceDocuments::url($type, $d, $portal)) ?>" target="_blank" rel="noopener" aria-label="Download <?= e($d[$noKey]) ?>"><?= icon('file-down', 'size-4') ?></a>
                </li>
            <?php endforeach ?>
        <?php endforeach ?>
    </ul>
<?php endif ?>
<?php if (!empty($pendingInvoices)): ?>
    <p class="mt-3 flex items-center gap-2 rounded-xl bg-amber-50 p-2.5 text-xs font-semibold text-amber-900 ring-1 ring-amber-200"><?= icon('inbox', 'size-4') ?><?= count($pendingInvoices) ?> item<?= count($pendingInvoices) === 1 ? '' : 's' ?> verified and waiting in the <a class="underline" href="<?= e(url('staff.invoices.index', ['tab' => 'queue'])) ?>">invoice queue</a>.</p>
<?php endif ?>
