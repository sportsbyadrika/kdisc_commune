<?php
/**
 * Price breakdown from a Quote::toArray() payload (live quote, or bookings.quote_json snapshot).
 *
 * @var array<string, mixed> $q
 */
$t = $q['totals'];
$m = static fn (float|int $v): string => money($v, fmod((float) $v, 1.0) !== 0.0 ? 2 : 0);
$rate = (float) ($q['lines'][0]['gst_rate'] ?? 18);
$pay = $q['payment'];
?>
<dl class="space-y-3 text-sm">
    <?php foreach ($q['lines'] as $l): ?>
        <div class="flex items-start justify-between gap-4">
            <dt class="min-w-0"><span class="block font-semibold"><?= e(trim(($l['emoji'] ?? '') . ' ' . $l['label'])) ?></span><span class="block text-xs text-muted"><?= e($l['detail']) ?></span></dt>
            <dd class="shrink-0 font-semibold tabular-nums"><?= e($m((float) $l['amount'])) ?></dd>
        </div>
    <?php endforeach ?>
</dl>
<dl class="mt-4 space-y-1.5 border-t border-line pt-4 text-sm">
    <div class="flex justify-between text-muted"><dt>Subtotal</dt><dd class="tabular-nums"><?= e($m((float) $t['taxable'])) ?></dd></div>
    <?php if ($q['tax_mode'] === 'inter'): ?>
        <div class="flex justify-between text-muted"><dt>IGST <?= e(rtrim(rtrim(number_format($rate, 2), '0'), '.')) ?>% <span class="text-xs">(inter-state)</span></dt><dd class="tabular-nums"><?= e(money($t['igst'], 2)) ?></dd></div>
    <?php else: ?>
        <div class="flex justify-between text-muted"><dt>CGST <?= e(rtrim(rtrim(number_format($rate / 2, 2), '0'), '.')) ?>%</dt><dd class="tabular-nums"><?= e(money($t['cgst'], 2)) ?></dd></div>
        <div class="flex justify-between text-muted"><dt>SGST <?= e(rtrim(rtrim(number_format($rate / 2, 2), '0'), '.')) ?>%</dt><dd class="tabular-nums"><?= e(money($t['sgst'], 2)) ?></dd></div>
    <?php endif ?>
    <div class="flex items-end justify-between pt-2"><dt class="font-bold">Total</dt><dd class="font-display text-3xl font-extrabold tabular-nums"><?= e($m((float) $t['grand'])) ?></dd></div>
</dl>
<div class="mt-4 flex items-start gap-3 rounded-2xl bg-surface p-3.5">
    <?= $this->component('badge', ['label' => $pay['rule'] === 'advance' ? 'Advance' : 'Security deposit', 'tone' => $pay['rule'] === 'advance' ? 'brand' : 'info', 'class' => 'shrink-0']) ?>
    <p class="text-xs text-muted">
        <?php if ($pay['rule'] === 'advance'): ?>
            Tenure up to 6 months: pay <b class="text-ink"><?= e($m((float) $pay['payable_now'])) ?></b> in advance once the Centre Manager approves — then your seats are allotted.
        <?php else: ?>
            Longer than 6 months: a refundable security deposit of <b class="text-ink"><?= e(money($pay['deposit'])) ?></b>
            (<?= (int) round((float) $pay['deposit'] / max(1, (float) $pay['monthly_rent'])) ?> months’ rent, no GST) secures the tenure; rent is then billed monthly.
        <?php endif ?>
    </p>
</div>
