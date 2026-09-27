<?php
/**
 * Finance payment verification queue (spec 6.4): logged payments oldest first, filters, proof viewer,
 * Verify (issues the receipt) / Query back to the front desk / bulk verify; Void stays with the Centre Manager.
 *
 * @var App\Core\Template $this
 * @var array<string, string> $filters
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int, sum: float} $result
 * @var array{pending: int, queried: int, verified_today: int, pending_amount: float, oldest_days: int} $counts
 * @var bool $canVerify
 * @var bool $canVoid
 */
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\PaymentVerificationService;

$this->layout('layouts/staff', ['title' => 'Payment verification', 'subtitle' => 'Payments logged at the front desk wait here until Finance checks them against the bank / UPI statement.', 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Finance'], ['Verify payments']]]);
$query = array_filter($filters, static fn ($v) => $v !== '');
$tabs = ['pending' => ['To verify', 'hourglass', $counts['pending']], 'queried' => ['Queried', 'message-circle-question', $counts['queried']], 'verified' => ['Verified', 'badge-check', null], 'void' => ['Void / rejected', 'circle-x', null], 'all' => ['All', 'list', null]];
$bulk = $canVerify && in_array($filters['status'], ['pending', 'queried', 'all'], true);
?>
<?php $this->start('head') ?><script defer src="<?= e(asset('assets/js/finance.js')) ?>"></script><?php $this->stop() ?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.invoices.index')) ?>" class="btn btn-outline"><?= icon('receipt-indian-rupee', 'size-4') ?><span class="hidden sm:inline">Invoice queue</span></a>
<?php $this->stop() ?>

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?= $this->component('stat', ['label' => 'Waiting for verification', 'value' => $counts['pending'], 'icon' => 'hourglass', 'tone' => $counts['pending'] > 0 ? 'warning' : 'success', 'hint' => money($counts['pending_amount'], 2) . ' logged']) ?>
    <?= $this->component('stat', ['label' => 'Queried with front desk', 'value' => $counts['queried'], 'icon' => 'message-circle-question', 'tone' => $counts['queried'] > 0 ? 'accent' : 'info']) ?>
    <?= $this->component('stat', ['label' => 'Verified today', 'value' => $counts['verified_today'], 'icon' => 'check-check', 'tone' => 'success']) ?>
    <?= $this->component('stat', ['label' => 'Oldest waiting', 'value' => $counts['pending'] > 0 ? $counts['oldest_days'] . ' day' . ($counts['oldest_days'] === 1 ? '' : 's') : '—', 'icon' => 'clock', 'tone' => $counts['oldest_days'] > 3 ? 'danger' : 'brand', 'hint' => 'since the payment date']) ?>
</div>

<nav class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Payment status">
    <div class="flex w-max gap-2 pb-1">
        <?php foreach ($tabs as $key => [$label, $ic, $n]): $on = $filters['status'] === $key; ?>
            <a href="<?= e(url('staff.payments.index', ['status' => $key] + array_diff_key($query, ['status' => 1]))) ?>" class="chip <?= $on ? 'chip-active' : '' ?>" <?= $on ? 'aria-current="page"' : '' ?>>
                <?= icon($ic, 'size-4') ?><?= e($label) ?><?php if ($n !== null): ?><span class="rounded-full px-1.5 text-xs font-bold <?= $on ? 'bg-white/20' : ($n > 0 ? 'bg-accent-500 text-white' : 'bg-surface-2 text-muted') ?>"><?= (int) $n ?></span><?php endif ?>
            </a>
        <?php endforeach ?>
    </div>
</nav>

<form method="get" class="card mb-5 grid gap-3 p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-4 xl:grid-cols-8 xl:items-end">
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <div class="sm:col-span-2 lg:col-span-2"><?= $this->component('input', ['name' => 'q', 'label' => 'Search', 'value' => $filters['q'], 'icon' => 'search', 'placeholder' => 'Booking, visitor, Unique ID, reference']) ?></div>
    <?= $this->component('select', ['name' => 'mode', 'label' => 'Mode', 'value' => $filters['mode'], 'placeholder' => 'Any mode', 'options' => PaymentMode::options()]) ?>
    <?= $this->component('select', ['name' => 'kind', 'label' => 'For', 'value' => $filters['kind'], 'placeholder' => 'Anything', 'options' => PaymentKind::options()]) ?>
    <?= $this->component('input', ['name' => 'from', 'label' => 'Paid from', 'type' => 'date', 'value' => $filters['from']]) ?>
    <?= $this->component('input', ['name' => 'to', 'label' => 'Paid to', 'type' => 'date', 'value' => $filters['to']]) ?>
    <div class="flex gap-2 sm:col-span-2 lg:col-span-2">
        <?= $this->component('select', ['name' => 'sort', 'label' => 'Sort', 'value' => $filters['sort'], 'options' => PaymentVerificationService::SORTS, 'class' => 'min-w-0 flex-1']) ?>
        <button class="btn btn-brand self-end" aria-label="Apply filters"><?= icon('filter', 'size-4') ?></button>
    </div>
</form>

<?php if ($result['rows'] === []): ?>
    <?= $this->component('empty', ['icon' => 'check-check', 'title' => $filters['status'] === 'pending' ? 'All caught up' : 'Nothing here', 'text' => $filters['status'] === 'pending' ? 'Every logged payment has been verified. New payments appear here as soon as the front desk logs them.' : 'No payments match these filters.']) ?>
<?php else: ?>
    <form id="bulk-verify" method="post" action="<?= e(url('staff.payments.bulk_verify')) ?>">
        <?= csrf_field() ?>
    </form>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted"><?= (int) $result['total'] ?> payment<?= $result['total'] === 1 ? '' : 's' ?> · <b class="text-ink"><?= e(money($result['sum'], 2)) ?></b></p>
        <?php if ($bulk): ?>
            <button form="bulk-verify" class="btn btn-brand btn-sm" data-bulk-submit><?= icon('check-check', 'size-4') ?>Verify selected</button>
        <?php endif ?>
    </div>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr>
                <?php if ($bulk): ?><th class="w-10"><input type="checkbox" class="size-4 accent-brand-600" aria-label="Select all" data-select-all="ids[]"></th><?php endif ?>
                <th>Paid on</th><th>Booking · visitor</th><th>For</th><th>Mode · reference</th><th class="text-right">Amount</th><th>Status</th><th class="text-right">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $p):
                $ps = PaymentStatus::from((string) $p['status']);
                $logged = $ps === PaymentStatus::Logged;
                $queried = $logged && $p['queried_at'] !== null && $p['query_resolved_at'] === null;
                $age = (int) $p['age_days']; ?>
                <tr class="<?= $queried ? 'bg-accent-50/40' : '' ?>">
                    <?php if ($bulk): ?><td><?php if ($logged): ?><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" form="bulk-verify" class="size-4 accent-brand-600" aria-label="Select payment <?= (int) $p['id'] ?>" <?= $queried ? 'disabled title="Open query — verify it on its own"' : '' ?>><?php endif ?></td><?php endif ?>
                    <td class="whitespace-nowrap"><div class="font-semibold"><?= e(format_date((string) $p['paid_on'], 'd M Y')) ?></div>
                        <?php if ($logged): ?><div class="text-xs font-bold <?= $age > 3 ? 'text-red-600' : ($age > 1 ? 'text-amber-700' : 'text-muted') ?>"><?= $age <= 0 ? 'today' : $age . ' day' . ($age === 1 ? '' : 's') . ' ago' ?></div>
                        <?php else: ?><div class="text-xs text-muted">logged <?= e(format_date((string) $p['created_at'], 'd M')) ?></div><?php endif ?>
                        <div class="text-xs text-muted">by <?= e($p['logged_by_name'] ?? '—') ?></div></td>
                    <td><a class="font-mono font-bold text-brand-700 hover:underline" href="<?= e(url('staff.bookings.show', ['no' => $p['booking_no']])) ?>#payments"><?= e($p['booking_no']) ?></a>
                        <div class="max-w-52 truncate text-sm font-semibold"><?= e($p['customer_name']) ?></div>
                        <div class="font-mono text-xs text-muted"><?= e($p['unique_id'] ?? '—') ?></div></td>
                    <td><?= $this->component('badge', ['label' => PaymentKind::from((string) $p['kind'])->label(), 'tone' => PaymentKind::from((string) $p['kind'])->tone()]) ?></td>
                    <td><div class="font-semibold"><?= e(PaymentMode::from((string) $p['mode'])->label()) ?></div>
                        <div class="max-w-48 truncate font-mono text-xs text-muted"><?= e($p['reference_no'] ?? '—') ?></div>
                        <?php if (!empty($p['proof_path'])): ?>
                            <button type="button" class="mt-0.5 inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline" @click="$dispatch('open-modal', 'proof-<?= (int) $p['id'] ?>')"><?= icon('file-check', 'size-3.5') ?>View proof</button>
                        <?php endif ?></td>
                    <td class="text-right font-bold tabular-nums"><?= e(money($p['amount'], 2)) ?></td>
                    <td class="max-w-56 whitespace-normal">
                        <?= $this->component('badge', ['label' => $queried ? 'Queried' : $ps->label(), 'tone' => $queried ? 'accent' : $ps->tone(), 'dot' => true]) ?>
                        <?php if ($p['verified_by_name']): ?><div class="mt-1 text-xs text-muted">verified by <?= e($p['verified_by_name']) ?></div><?php endif ?>
                        <?php if ($p['query_note']): ?>
                            <div class="mt-1 rounded-lg bg-white/70 p-1.5 text-xs ring-1 ring-line"><b>Q:</b> <?= e($p['query_note']) ?><?= $p['query_reply'] ? '<br><b>A:</b> ' . e($p['query_reply']) : '' ?></div>
                        <?php endif ?>
                        <?php if ($p['receipt_no']): ?><a class="mt-1 inline-flex items-center gap-1 font-mono text-xs font-semibold text-brand-700 hover:underline" href="<?= e(FinanceDocuments::url('receipt', ['id' => $p['receipt_id'], 'receipt_no' => $p['receipt_no']])) ?>" target="_blank" rel="noopener"><?= icon('receipt', 'size-3.5') ?><?= e($p['receipt_no']) ?></a><?php endif ?>
                    </td>
                    <td class="text-right">
                        <?php if ($logged): ?>
                            <div class="flex justify-end gap-1.5">
                                <?php if ($canVerify): ?>
                                    <form method="post" action="<?= e(url('staff.payments.verify', ['id' => $p['id']])) ?>"><?= csrf_field() ?><button class="btn btn-brand btn-sm"><?= icon('check', 'size-4') ?>Verify</button></form>
                                    <button type="button" class="btn btn-outline btn-sm" @click="$dispatch('open-modal', 'query-<?= (int) $p['id'] ?>')" title="Send back to the front desk"><?= icon('message-circle-question', 'size-4') ?><span class="sr-only">Query</span></button>
                                <?php endif ?>
                                <?php if ($canVoid): ?>
                                    <button type="button" class="btn btn-ghost btn-sm text-red-700" @click="$dispatch('open-modal', 'void-<?= (int) $p['id'] ?>')">Void</button>
                                <?php endif ?>
                            </div>
                        <?php else: ?>
                            <span class="text-xs text-muted"><?= $p['verified_at'] ? e(format_date((string) $p['verified_at'], 'd M, g:i a')) : '' ?></span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <div class="mt-5"><?= $this->component('pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'], 'perPage' => $result['per_page'], 'route' => 'staff.payments.index', 'query' => $query]) ?></div>

    <?php foreach ($result['rows'] as $p): $isImage = str_starts_with((string) ($p['proof_mime'] ?? ''), 'image/'); ?>
        <?php if (!empty($p['proof_path'])): ?>
            <?php $this->begin('modal', ['id' => 'proof-' . (int) $p['id'], 'title' => 'Payment proof · ' . money($p['amount'], 2) . ' · ' . $p['booking_no'], 'size' => 'lg']) ?>
                <div class="overflow-hidden rounded-2xl bg-surface ring-1 ring-line">
                    <?php if ($isImage): ?>
                        <img src="<?= e(url('staff.payments.proof', ['id' => $p['id']])) ?>" alt="Payment proof" class="mx-auto max-h-[65vh] w-auto" loading="lazy">
                    <?php else: ?>
                        <iframe src="<?= e(url('staff.payments.proof', ['id' => $p['id']])) ?>" title="Payment proof" class="h-[65vh] w-full" loading="lazy"></iframe>
                    <?php endif ?>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-muted">Mode</dt><dd class="font-semibold"><?= e(PaymentMode::from((string) $p['mode'])->label()) ?></dd></div>
                    <div><dt class="text-xs text-muted">Reference</dt><dd class="font-mono font-semibold"><?= e($p['reference_no'] ?? '—') ?></dd></div>
                    <div><dt class="text-xs text-muted">Paid on</dt><dd class="font-semibold"><?= e(format_date((string) $p['paid_on'])) ?></dd></div>
                    <div><dt class="text-xs text-muted">Amount</dt><dd class="font-bold"><?= e(money($p['amount'], 2)) ?></dd></div>
                </dl>
                <p class="mt-3 text-right"><a class="text-sm font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.payments.proof', ['id' => $p['id']])) ?>" target="_blank" rel="noopener">Open in a new tab <?= icon('external-link', 'size-3.5') ?></a></p>
            <?= $this->end() ?>
        <?php endif ?>
        <?php if ($canVerify && $p['status'] === 'logged'): ?>
            <?php $this->begin('modal', ['id' => 'query-' . (int) $p['id'], 'title' => 'Query this payment', 'size' => 'sm']) ?>
                <form method="post" action="<?= e(url('staff.payments.query', ['id' => $p['id']])) ?>" class="space-y-4">
                    <?= csrf_field() ?>
                    <p class="text-sm"><?= e(money($p['amount'], 2)) ?> · <?= e(PaymentMode::from((string) $p['mode'])->label()) ?> · <?= e($p['reference_no'] ?? 'no reference') ?> on <b class="font-mono"><?= e($p['booking_no']) ?></b>. The front desk is notified and replies from the booking page.</p>
                    <?= $this->component('textarea', ['name' => 'note', 'id' => 'query-note-' . (int) $p['id'], 'label' => 'Question for the front desk', 'rows' => 3, 'required' => true, 'placeholder' => 'e.g. UTR not found in the bank statement for 26 Sep — please re-check the reference.']) ?>
                    <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'query-<?= (int) $p['id'] ?>')">Cancel</button><button class="btn btn-brand"><?= icon('send', 'size-4') ?>Send query</button></div>
                </form>
            <?= $this->end() ?>
        <?php endif ?>
        <?php if ($canVoid && $p['status'] === 'logged'): ?>
            <?php $this->begin('modal', ['id' => 'void-' . (int) $p['id'], 'title' => 'Void payment of ' . money($p['amount'], 2) . '?', 'size' => 'sm']) ?>
                <form method="post" action="<?= e(url('staff.payments.void', ['id' => $p['id']])) ?>" class="space-y-4">
                    <?= csrf_field() ?>
                    <p class="text-sm">The payment stays on record marked <b>void</b> with your reason.</p>
                    <?= $this->component('textarea', ['name' => 'reason', 'id' => 'void-reason-' . (int) $p['id'], 'label' => 'Reason', 'rows' => 2, 'required' => true, 'placeholder' => 'e.g. Duplicate entry, wrong amount']) ?>
                    <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'void-<?= (int) $p['id'] ?>')">Keep</button><button class="btn bg-red-600 text-white hover:bg-red-700">Void payment</button></div>
                </form>
            <?= $this->end() ?>
        <?php endif ?>
    <?php endforeach ?>
<?php endif ?>
