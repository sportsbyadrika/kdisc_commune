<?php
/**
 * Dues of a booking (PaymentLedger::dues()): the confirmation rule, totals, and the rent schedule for
 * > 6-month bookings. Shared by the staff booking page and the visitor portal.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $dues
 * @var array<string, mixed> $booking
 * @var bool|null $staff
 */
use App\Enums\BookingStatus;
use App\Enums\PaymentRule;

$staff ??= false;
$m = static fn (float|int|string $v): string => money($v, fmod((float) $v, 1.0) !== 0.0 ? 2 : 0);
$req = $dues['requirement'];
$status = BookingStatus::from((string) $booking['status']);
$deposit = $dues['rule'] === PaymentRule::SecurityDeposit;
$paidPct = $dues['total'] > 0 ? min(100, (int) round($dues['paid'] / $dues['total'] * 100)) : 0;
$stateTone = ['paid' => 'success', 'partial' => 'info', 'due' => 'warning', 'overdue' => 'danger', 'upcoming' => 'neutral'];
?>
<div class="rounded-2xl p-4 ring-1 <?= $dues['confirmation_met'] ? 'bg-emerald-50 ring-emerald-200' : 'bg-amber-50 ring-amber-200' ?>">
    <div class="flex items-start gap-3">
        <span class="grid size-9 shrink-0 place-items-center rounded-xl <?= $dues['confirmation_met'] ? 'bg-emerald-500 text-white' : 'bg-amber-400 text-white' ?>"><?= icon($dues['confirmation_met'] ? 'circle-check' : 'hourglass', 'size-5') ?></span>
        <div class="min-w-0">
            <p class="text-sm font-bold <?= $dues['confirmation_met'] ? 'text-emerald-900' : 'text-amber-900' ?>">
                To confirm: <?= e($req['label']) ?> · <?= e($m($req['total'])) ?>
                <?= $dues['confirmation_met'] ? '— received' : '' ?>
            </p>
            <p class="mt-0.5 text-xs <?= $dues['confirmation_met'] ? 'text-emerald-800/80' : 'text-amber-900/80' ?>"><?= e($req['summary']) ?></p>
            <?php if ($status === BookingStatus::Approved && !empty($booking['payment_due_by'])): ?>
                <p class="mt-1 text-xs font-bold text-amber-900">Pay by <?= e(format_date((string) $booking['payment_due_by'], 'D, d M Y')) ?> — unpaid approvals expire after that.</p>
            <?php endif ?>
        </div>
    </div>
</div>

<dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold tracking-wide text-muted uppercase">Total</dt><dd class="mt-1 font-bold tabular-nums"><?= e($m($dues['total'])) ?></dd>
        <?php if ($deposit): ?><dd class="text-[11px] text-muted">incl. deposit <?= e($m($dues['deposit']['due'])) ?></dd><?php endif ?></div>
    <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold tracking-wide text-muted uppercase">Paid</dt><dd class="mt-1 font-bold text-emerald-700 tabular-nums"><?= e($m($dues['paid'])) ?></dd></div>
    <div class="rounded-2xl bg-surface p-3"><dt class="text-[11px] font-bold tracking-wide text-muted uppercase">Balance</dt><dd class="mt-1 font-bold tabular-nums"><?= e($m($dues['balance'])) ?></dd></div>
    <div class="rounded-2xl p-3 <?= $dues['due_now'] > 0 ? 'bg-red-50 ring-1 ring-red-100' : 'bg-surface' ?>"><dt class="text-[11px] font-bold tracking-wide uppercase <?= $dues['due_now'] > 0 ? 'text-red-700' : 'text-muted' ?>">Due now</dt><dd class="mt-1 font-bold tabular-nums <?= $dues['due_now'] > 0 ? 'text-red-700' : '' ?>"><?= e($m($dues['due_now'])) ?></dd></div>
</dl>
<div class="mt-3 h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuenow="<?= $paidPct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Paid">
    <div class="h-full rounded-full bg-emerald-500" style="width: <?= $paidPct ?>%"></div>
</div>
<?php if (!empty($dues['next_due']) && $dues['balance'] > 0): ?>
    <p class="mt-2 text-xs text-muted">Next: <b class="text-ink"><?= e($dues['next_due']['label']) ?></b> · <?= e($m($dues['next_due']['amount'])) ?> due <?= e(format_date((string) $dues['next_due']['date'], 'd M Y')) ?></p>
<?php endif ?>
<?php if ($dues['overpaid'] > 0): ?>
    <p class="mt-2 rounded-xl bg-sky-50 p-2 text-xs text-sky-900">Paid <?= e($m($dues['overpaid'])) ?> more than the current total — Finance will adjust or refund it.</p>
<?php endif ?>

<?php if ($dues['schedule'] !== []): ?>
    <h3 class="mt-5 mb-2 text-xs font-bold tracking-[0.14em] text-muted uppercase">Rent schedule · monthly</h3>
    <div class="overflow-x-auto rounded-2xl border border-line">
        <table class="table text-sm">
            <thead><tr><th>#</th><th>Period · due</th><th class="text-right">Rent + GST</th><th class="text-right">Paid</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($dues['schedule'] as $p): ?>
                <tr>
                    <td class="text-muted tabular-nums"><?= (int) $p['period_no'] ?></td>
                    <td class="whitespace-nowrap"><?= e(format_date((string) $p['period_start'], 'd M') . ' – ' . format_date((string) $p['period_end'], 'd M Y')) ?><div class="text-[11px] text-muted">due <?= e(format_date((string) $p['due_on'], 'd M Y')) ?></div></td>
                    <td class="text-right whitespace-nowrap tabular-nums"><?= e(money($p['amount'], 2)) ?><?php if ($staff): ?><div class="text-[11px] text-muted"><?= e(money($p['taxable'], 2)) ?> + <?= e(money($p['gst'], 2)) ?> GST</div><?php endif ?></td>
                    <td class="text-right tabular-nums"><?= e(money($p['paid'], 2)) ?></td>
                    <td><?= $this->component('badge', ['label' => ucfirst((string) $p['state']), 'tone' => $stateTone[$p['state']] ?? 'neutral']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
