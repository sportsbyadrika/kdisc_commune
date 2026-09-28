<?php
/**
 * Read-only profile summary (review step, profile page, staff pages). Only masked Aadhaar is shown.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer   safe row
 * @var array<string, mixed>|null $signatory
 * @var string|null $editBase  portal: link each section to its wizard step
 */
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Kyc\AadhaarVault;
use App\Support\IndianStates;

$c = $customer;
$type = CustomerType::from((string) $c['type']);
$individual = $type === CustomerType::Individual;
$foreign = Customer::isForeign($c);
$sub = CustomerSubCategory::tryFrom((string) ($c['sub_category'] ?? ''));
$dash = static fn (mixed $v): string => (string) ($v ?? '') !== '' ? (string) $v : '—';
$sections = [
    1 => [$individual ? 'Basic details' : 'Institution details', [
        [$individual ? 'Category' : 'Institution type', $sub?->label()],
        [$individual ? 'Full name' : 'Institution name', $c['name'] ?? null],
        [$individual ? 'Email' : 'Official email', $c['email'] ?? null],
        [$individual ? 'Mobile' : 'Official phone', format_phone($c['mobile'] ?? null) ?: null],
        ['Address', trim(implode(', ', array_filter([(string) ($c['address'] ?? ''), (string) ($c['city'] ?? ''), IndianStates::name($c['state_code'] ?? null), (string) ($c['pincode'] ?? '')])), ', ')],
        [$individual ? 'Professional summary' : 'Institution profile', $c['profile'] ?? null, true],
    ]],
    2 => ['Identity & KYC', $individual ? array_values(array_filter([
        ['Nationality', $foreign ? $c['nationality'] : 'Indian citizen'],
        $foreign ? ['Passport number', $c['passport_no'] ?? null, false, true] : ['Aadhaar', ($c['aadhaar_last4'] ?? '') !== '' ? AadhaarVault::mask($c['aadhaar_last4']) : null, false, true],
        ['PAN', $c['pan'] ?? null, false, true],
        ['GSTIN', $c['gstin'] ?? null, false, true],
        !$foreign ? ['Aadhaar consent', !empty($c['consent_at']) ? 'Given on ' . format_date((string) $c['consent_at'], 'd M Y, g:i a') : null] : null,
    ])) : [
        ['PAN', $c['pan'] ?? null, false, true],
        ['GSTIN', $c['gstin'] ?? null, false, true],
        ['TAN', $c['tan'] ?? null, false, true],
        ['Signatory', $signatory !== null ? trim(($signatory['name'] ?? '') . ($signatory['designation'] ? ' · ' . $signatory['designation'] : '')) : null],
        ['Signatory contact', $signatory !== null ? trim(($signatory['email'] ?? '') . ' · ' . format_phone($signatory['mobile'] ?? null), ' ·') : null],
        ["Signatory's Aadhaar", ($signatory['aadhaar_last4'] ?? '') !== '' ? AadhaarVault::mask($signatory['aadhaar_last4']) : null, false, true],
        ['Aadhaar consent', !empty($c['consent_at']) ? 'Given on ' . format_date((string) $c['consent_at'], 'd M Y, g:i a') : null],
    ]],
];
?>
<div class="space-y-5">
    <?php foreach ($sections as $step => [$heading, $rows]): ?>
        <section class="card overflow-hidden">
            <header class="flex items-center justify-between gap-3 border-b border-line bg-surface/60 px-5 py-3">
                <h3 class="text-sm font-bold"><?= e($heading) ?></h3>
                <?php if (!empty($editBase)): ?><a href="<?= e(url('portal.wizard', ['step' => $step])) ?>" class="btn btn-ghost btn-sm"><?= icon('pencil', 'size-3.5') ?> Edit</a><?php endif ?>
            </header>
            <dl class="divide-y divide-line">
                <?php foreach ($rows as $row):
                    [$label, $value] = $row;
                    $wide = !empty($row[2]);
                    $mono = !empty($row[3]);
                ?>
                    <div class="<?= e(class_names('px-5 py-3 text-sm', $wide ? 'block' : 'grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-4')) ?>">
                        <dt class="font-medium text-muted"><?= e($label) ?></dt>
                        <dd class="<?= e(class_names($wide ? 'mt-1 whitespace-pre-line text-ink/85' : 'sm:col-span-2 text-ink break-words', ['font-mono tracking-wide' => $mono && $value !== null])) ?>"><?= e($dash($value)) ?></dd>
                    </div>
                <?php endforeach ?>
            </dl>
        </section>
    <?php endforeach ?>
</div>
