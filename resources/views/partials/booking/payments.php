<?php
/**
 * Payment history of a booking (never deleted — void entries stay with their reason).
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $payments
 * @var bool|null $staff      show logged-by, proof links
 * @var bool|null $canVoid    show the void button on logged payments
 * @var bool|null $canReply   staff: answer an open Finance query (query_note) on a logged payment
 */
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;

$staff ??= false;
$canVoid ??= false;
$canReply ??= false;
?>
<?php if ($payments === []): ?>
    <p class="rounded-2xl border-2 border-dashed border-line p-5 text-center text-sm text-muted">No payments yet.</p>
<?php else: ?>
    <ul class="divide-y divide-line rounded-2xl border border-line">
        <?php foreach ($payments as $p):
            $ps = PaymentStatus::from((string) $p['status']);
            $void = $ps === PaymentStatus::Void; ?>
            <li class="flex flex-wrap items-start gap-3 p-3.5 sm:flex-nowrap <?= $void ? 'bg-surface/60' : '' ?>">
                <span class="grid size-9 shrink-0 place-items-center rounded-xl <?= $void ? 'bg-surface-2 text-muted' : 'bg-emerald-50 text-emerald-700' ?>"><?= icon($void ? 'circle-x' : 'indian-rupee', 'size-4') ?></span>
                <div class="min-w-0 flex-1 basis-40">
                    <p class="font-bold tabular-nums <?= $void ? 'text-muted line-through' : '' ?>"><?= e(money($p['amount'], 2)) ?>
                        <span class="ml-1 text-sm font-semibold text-muted no-underline"><?= e(PaymentKind::from((string) $p['kind'])->label()) ?> · <?= e(PaymentMode::from((string) $p['mode'])->label()) ?></span></p>
                    <p class="text-xs text-muted"><?= e(format_date((string) $p['paid_on'], 'D, d M Y')) ?><?= $p['reference_no'] ? ' · Ref ' . e($p['reference_no']) : '' ?><?= $staff && !empty($p['logged_by_name']) ? ' · logged by ' . e($p['logged_by_name']) : '' ?></p>
                    <?php if (!empty($p['remarks'])): ?><p class="mt-1 text-xs text-ink/70"><?= e($p['remarks']) ?></p><?php endif ?>
                    <?php if ($staff && !empty($p['query_note'])): $open = $p['query_resolved_at'] === null && $ps === PaymentStatus::Logged; ?>
                        <div class="mt-2 rounded-xl p-2.5 text-xs <?= $open ? 'bg-accent-50 ring-1 ring-accent-100' : 'bg-surface' ?>">
                            <p><b class="<?= $open ? 'text-accent-600' : '' ?>">Finance query:</b> <?= e($p['query_note']) ?></p>
                            <?php if (!empty($p['query_reply'])): ?><p class="mt-1"><b>Reply:</b> <?= e($p['query_reply']) ?></p><?php endif ?>
                            <?php if ($open && $canReply): ?>
                                <form method="post" action="<?= e(url('staff.payments.reply', ['id' => $p['id']])) ?>" class="mt-2 flex gap-2">
                                    <?= csrf_field() ?>
                                    <input name="reply" required minlength="2" maxlength="500" class="input !py-1.5 text-xs" placeholder="Reply to Finance (e.g. corrected UTR…)" aria-label="Reply to Finance">
                                    <button class="btn btn-brand btn-sm shrink-0"><?= icon('send', 'size-3.5') ?>Reply</button>
                                </form>
                            <?php endif ?>
                        </div>
                    <?php endif ?>
                    <?php if ($void): ?><p class="mt-1 text-xs font-semibold text-red-700">Void: <?= e((string) $p['void_reason']) ?><?= $staff && !empty($p['voided_by_name']) ? ' — ' . e($p['voided_by_name']) : '' ?></p><?php endif ?>
                    <?php if ($staff && !empty($p['proof_path'])): ?><a class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.payments.proof', ['id' => $p['id']])) ?>" target="_blank" rel="noopener"><?= icon('file-check', 'size-3.5') ?>Proof</a><?php endif ?>
                </div>
                <div class="flex w-full shrink-0 items-center justify-between gap-2 pl-12 sm:w-auto sm:flex-col sm:items-end sm:pl-0">
                    <?= $this->component('badge', ['label' => $ps === PaymentStatus::Logged ? ($staff ? ($p['queried_at'] !== null && $p['query_resolved_at'] === null ? 'Queried by Finance' : 'Logged · awaiting Finance') : 'Received') : $ps->label(), 'tone' => !$staff && $ps === PaymentStatus::Logged ? 'success' : ($staff && $p['queried_at'] !== null && $p['query_resolved_at'] === null && $ps === PaymentStatus::Logged ? 'accent' : $ps->tone())]) ?>
                    <?php if ($canVoid && $ps === PaymentStatus::Logged): ?>
                        <button type="button" class="text-xs font-semibold text-red-700 hover:underline" @click="$dispatch('open-modal', 'void-<?= (int) $p['id'] ?>')">Void</button>
                    <?php endif ?>
                </div>
            </li>
            <?php if ($canVoid && $ps === PaymentStatus::Logged): ?>
                <?php $this->begin('modal', ['id' => 'void-' . (int) $p['id'], 'title' => 'Void payment of ' . money($p['amount'], 2) . '?', 'size' => 'sm']) ?>
                    <form method="post" action="<?= e(url('staff.payments.void', ['id' => $p['id']])) ?>" class="space-y-4">
                        <?= csrf_field() ?>
                        <p>The payment stays on record marked <b>void</b>. If the booking was confirmed by it, it stays confirmed — check the dues.</p>
                        <?= $this->component('textarea', ['name' => 'reason', 'id' => 'void-reason-' . (int) $p['id'], 'label' => 'Reason', 'rows' => 2, 'required' => true, 'placeholder' => 'e.g. Duplicate entry, wrong amount']) ?>
                        <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="$dispatch('close-modal', 'void-<?= (int) $p['id'] ?>')">Keep</button><button class="btn bg-red-600 text-white hover:bg-red-700">Void payment</button></div>
                    </form>
                <?= $this->end() ?>
            <?php endif ?>
        <?php endforeach ?>
    </ul>
<?php endif ?>
