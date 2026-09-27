<?php
/**
 * Visitor portal: GST invoices, receipts, credit notes, deposit refunds, allotment letters and the ID card.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer safe row
 * @var array<string, list<array<string, mixed>>> $docs FinanceDocuments::forCustomer()
 * @var list<array<string, mixed>> $letters confirmed / active / completed bookings
 */
use App\Enums\PaymentMode;
use App\Services\Finance\FinanceDocuments;

$this->layout('layouts/portal', ['heading' => 'Invoices & receipts', 'subheading' => 'Download your GST invoices, payment receipts and booking letters.']);
$sections = [
    'invoice' => ['GST tax invoices', 'receipt-indian-rupee', 'invoice_no', 'invoice_date', 'total', 'Issued when a payment is verified — one per booking, or one per month for long tenures.'],
    'receipt' => ['Payment receipts', 'receipt', 'receipt_no', 'receipt_date', 'amount', 'One for every payment our finance team verifies, including security deposits.'],
    'credit_note' => ['Credit notes', 'file-minus', 'credit_note_no', 'note_date', 'total', 'Adjustments against an invoice — cancellations, early exits, discounts.'],
    'deposit_refund' => ['Deposit refunds', 'banknote', 'voucher_no', 'voucher_date', 'refund_amount', 'Settlement of your security deposit at the end of a tenure.'],
];
$total = array_sum(array_map('count', $docs));
?>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <div class="space-y-6 lg:col-span-2">
        <?php if ($total === 0): ?>
            <?= $this->component('empty', ['icon' => 'receipt-indian-rupee', 'title' => 'No documents yet', 'text' => 'Once you pay at the front desk and our finance team verifies the payment, your receipt and GST invoice appear here (and arrive by email).', 'action' => ['label' => 'My bookings', 'href' => url('portal.bookings'), 'variant' => 'outline']]) ?>
        <?php endif ?>
        <?php foreach ($sections as $type => [$title, $ic, $noKey, $dateKey, $amountKey, $hint]): ?>
            <?php if (($docs[$type] ?? []) === []) { continue; } ?>
            <section class="card overflow-hidden">
                <div class="flex items-center gap-3 px-5 pt-5">
                    <span class="grid size-10 place-items-center rounded-xl bg-brand-50 text-brand-700"><?= icon($ic, 'size-5') ?></span>
                    <div><h2 class="font-bold"><?= e($title) ?></h2><p class="text-xs text-muted"><?= e($hint) ?></p></div>
                </div>
                <ul class="mt-3 divide-y divide-line">
                    <?php foreach ($docs[$type] as $d): ?>
                        <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5">
                            <div class="min-w-0 flex-1 basis-48">
                                <p class="font-mono text-sm font-bold text-brand-900"><?= e($d[$noKey]) ?></p>
                                <p class="text-xs text-muted">
                                    <?= e(format_date((string) $d[$dateKey], 'd M Y')) ?>
                                    <?php if ($type === 'invoice'): ?> · <?= e($d['booking_no']) ?> · <?= e(format_date((string) $d['period_start'], 'd M') . ' – ' . format_date((string) $d['period_end'], 'd M Y')) ?><?= $d['status'] === 'cancelled' ? ' · credited' : '' ?>
                                    <?php elseif ($type === 'receipt'): ?> · <?= $d['kind'] === 'deposit' ? 'Security deposit' : 'Payment' ?> · <?= e(PaymentMode::tryFrom((string) $d['mode'])?->label() ?? '') ?> · <?= e($d['booking_no'] ?? '') ?>
                                    <?php elseif ($type === 'credit_note'): ?> · against <?= e($d['invoice_no']) ?> · <?= e($d['reason']) ?>
                                    <?php else: ?> · <?= e($d['booking_no']) ?><?php endif ?>
                                </p>
                            </div>
                            <p class="font-bold tabular-nums"><?= $type === 'credit_note' ? '− ' : '' ?><?= e(money($d[$amountKey], 2)) ?></p>
                            <a class="btn btn-outline btn-sm" href="<?= e(FinanceDocuments::url($type, $d, true)) ?>" target="_blank" rel="noopener"><?= icon('file-down', 'size-4') ?>PDF</a>
                        </li>
                    <?php endforeach ?>
                </ul>
            </section>
        <?php endforeach ?>
    </div>
    <aside class="space-y-6">
        <section class="card card-body">
            <h2 class="font-bold">Allotment letters</h2>
            <p class="text-xs text-muted">Your confirmation with the seat map — available once a booking is confirmed.</p>
            <?php if ($letters === []): ?>
                <p class="mt-3 text-sm text-muted">No confirmed bookings yet.</p>
            <?php else: ?>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($letters as $b): ?>
                        <li><a href="<?= e(url('portal.bookings.allotment', ['no' => $b['booking_no']])) ?>" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-2xl p-3 ring-1 ring-line transition hover:bg-surface">
                            <?= icon('scroll-text', 'size-5 text-brand-600') ?>
                            <span class="min-w-0 flex-1"><span class="block font-mono text-sm font-bold"><?= e($b['booking_no']) ?></span><span class="block truncate text-xs text-muted"><?= e($b['category_name']) ?> · <?= e((string) $b['seat_codes']) ?></span></span>
                            <?= icon('file-down', 'size-4 text-muted') ?>
                        </a></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </section>
        <?php if (!empty($customer['unique_id'])): ?>
            <section class="card card-body">
                <h2 class="font-bold">Visitor ID card</h2>
                <p class="text-xs text-muted">Credit-card size PDF with your photo and check-in QR code.</p>
                <a href="<?= e(url('portal.id_card')) ?>" target="_blank" rel="noopener" class="btn btn-brand mt-4 w-full"><?= icon('id-card', 'size-4') ?>Download ID card</a>
            </section>
        <?php endif ?>
        <p class="px-1 text-xs text-muted">Invoices are issued by <?= e((string) setting('supplier_legal_name', 'K-DISC')) ?><?= setting('org_gstin', '') !== '' ? ' (GSTIN ' . e((string) setting('org_gstin')) . ')' : '' ?>. Questions about a document? Call the front desk on <?= e((string) config('app.org.phone', '')) ?>.</p>
    </aside>
</div>
