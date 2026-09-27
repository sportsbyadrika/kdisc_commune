<?php
/**
 * KYC status timeline: registered → email verified → profile submitted → verified (or rejected).
 *
 * @var array<string, mixed> $customer
 * @var array<string, mixed>|null $account
 */
use App\Enums\KycStatus;

$status = KycStatus::from((string) $customer['kyc_status']);
$verifiedEmail = $account['email_verified_at'] ?? null;
$items = [
    ['Account created', $customer['created_at'], true, 'user-plus', ($customer['registered_via'] ?? '') === 'reception' ? 'Registered at the front desk' : 'Registered online'],
    ['Email verified', $verifiedEmail, $verifiedEmail !== null || ($customer['registered_via'] ?? '') === 'reception', 'mail-check', $verifiedEmail !== null ? 'Password set' : (($customer['registered_via'] ?? '') === 'reception' ? 'Not needed for desk registrations' : 'Use the link we emailed you')],
    ['Profile submitted', $customer['kyc_submitted_at'], $customer['kyc_submitted_at'] !== null, 'clipboard-check', $customer['unique_id'] !== null ? 'Unique ID ' . $customer['unique_id'] : 'Complete all 4 steps'],
];
$items[] = match ($status) {
    KycStatus::Verified => ['KYC verified', $customer['kyc_verified_at'], true, 'badge-check', 'You can confirm bookings'],
    KycStatus::Rejected => ['Changes requested', null, false, 'circle-x', (string) ($customer['kyc_remarks'] ?? 'See the note from the centre')],
    KycStatus::Pending => ['KYC verification', null, false, 'hourglass', 'The Centre Manager is reviewing your documents'],
    default => ['KYC verification', null, false, 'shield-check', 'After you submit your profile'],
};
?>
<ol class="relative space-y-0">
    <?php foreach ($items as $i => [$label, $at, $done, $ico, $sub]):
        $last = $i === count($items) - 1;
        $current = !$done && ($i === 0 || $items[$i - 1][2]);
        $tone = match (true) {
            $done => 'bg-emerald-500 text-white',
            $current && $status === KycStatus::Rejected => 'bg-red-500 text-white',
            $current => 'bg-amber-400 text-white ring-4 ring-amber-100',
            default => 'bg-surface-2 text-muted',
        };
    ?>
        <li class="relative flex gap-4 pb-6 last:pb-0">
            <?php if (!$last): ?><span class="<?= e(class_names('absolute top-10 left-[19px] h-[calc(100%-2.5rem)] w-0.5', $done ? 'bg-emerald-200' : 'bg-line')) ?>" aria-hidden="true"></span><?php endif ?>
            <span class="<?= e(class_names('relative grid size-10 shrink-0 place-items-center rounded-full', $tone)) ?>"><?= icon($done ? 'check' : $ico, 'size-5') ?></span>
            <div class="min-w-0 pt-1.5">
                <p class="text-sm font-bold <?= $done || $current ? 'text-ink' : 'text-muted' ?>"><?= e($label) ?></p>
                <p class="text-xs text-muted"><?= e($sub) ?><?= $at !== null ? ' · ' . e(format_date((string) $at, 'd M Y')) : '' ?></p>
            </div>
        </li>
    <?php endforeach ?>
</ol>
