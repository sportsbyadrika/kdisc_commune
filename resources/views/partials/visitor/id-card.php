<?php
/**
 * Visitor ID card with QR code (the QR holds the Unique Visitor ID for the front-desk scanner).
 *
 * @var array<string, mixed> $customer
 * @var string|null $qr  data URI
 * @var App\Enums\KycStatus $kyc
 * @var bool|null $compact  narrow sidebars (staff visitor page)
 */
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Enums\KycStatus;

$type = CustomerType::from((string) $customer['type']);
$sub = CustomerSubCategory::tryFrom((string) ($customer['sub_category'] ?? ''));
$compact = !empty($compact);
?>
<div class="relative overflow-hidden rounded-[1.75rem] bg-brand-950 p-6 text-white shadow-[var(--shadow-card-hover)] sm:p-7">
    <div class="pointer-events-none absolute -top-24 -right-20 size-72 rounded-full bg-accent-500/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-28 -left-16 size-72 rounded-full bg-brand-600/40 blur-3xl"></div>
    <div class="relative flex items-start justify-between gap-4">
        <?= $this->partial('partials/logo', ['inverse' => true, 'sub' => 'Visitor ID · Kottarakara']) ?>
        <?php if ($kyc === KycStatus::Verified): ?>
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-400/20 px-2.5 py-1 text-xs font-bold text-emerald-200 ring-1 ring-emerald-300/30"><?= icon('badge-check', 'size-3.5') ?> Verified</span>
        <?php else: ?>
            <span class="inline-flex items-center gap-1 shrink-0 whitespace-nowrap rounded-full bg-white/10 px-2.5 py-1 text-xs font-bold text-white/80 ring-1 ring-white/15"><?= e($kyc->label()) ?></span>
        <?php endif ?>
    </div>
    <div class="relative mt-8 flex items-end justify-between gap-5">
        <div class="min-w-0">
            <p class="text-[11px] font-bold tracking-[0.18em] text-white/70 uppercase">Unique Visitor ID</p>
            <?php if (!empty($customer['unique_id'])): ?>
                <p class="<?= $compact ? 'mt-1 font-mono text-base font-bold whitespace-nowrap' : 'mt-1 font-mono text-lg font-bold tracking-wide whitespace-nowrap sm:text-2xl' ?>"><?= e($customer['unique_id']) ?></p>
            <?php else: ?>
                <p class="mt-1 font-mono text-xl font-bold tracking-wide text-white/40">CMN-KTR-·-····-·····</p>
                <p class="mt-1 text-xs text-white/60">Issued when you submit your profile.</p>
            <?php endif ?>
            <p class="mt-5 truncate text-lg font-bold"><?= e($customer['name']) ?></p>
            <p class="text-sm text-white/65"><?= e($type->label()) ?><?= $sub !== null ? ' · ' . e($sub->label()) : '' ?></p>
        </div>
        <?php if (!empty($qr)): ?>
            <div class="shrink-0 rounded-2xl bg-white p-2 shadow-lg">
                <img src="<?= e($qr) ?>" alt="QR code for <?= e($customer['unique_id']) ?>" class="<?= $compact ? 'size-16' : 'size-20 sm:size-28' ?>" width="112" height="112">
            </div>
        <?php else: ?>
            <div class="grid size-28 shrink-0 place-items-center rounded-2xl border-2 border-dashed border-white/20 text-white/30"><?= icon('qr-code', 'size-10') ?></div>
        <?php endif ?>
    </div>
</div>
