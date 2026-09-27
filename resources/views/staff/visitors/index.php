<?php
/**
 * Visitor list with search + type / KYC filters.
 *
 * @var App\Core\Template $this
 * @var array{q: string, type: string, kyc: string} $filters
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int} $result
 * @var array<string, int> $counts
 * @var bool $canRegister
 */
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Services\Kyc\AadhaarVault;

$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Visitors']]]);
$kycChips = [['value' => '', 'label' => 'All', 'count' => array_sum($counts)]];
foreach (KycStatus::cases() as $k) {
    $kycChips[] = ['value' => $k->value, 'label' => $k->label(), 'count' => $counts[$k->value] ?? 0];
}
$chipItems = array_map(static fn (array $c) => $c + ['href' => url('staff.visitors.index', array_filter(['q' => $filters['q'], 'type' => $filters['type'], 'kyc' => $c['value']]))], $kycChips);
?>
<?php $this->start('actions') ?>
    <?= $this->partial('partials/report/export-buttons', ['key' => 'visitors', 'query' => $filters]) ?>
<?php if ($canRegister): ?>
        <a href="<?= e(url('staff.visitors.create', ['type' => 'individual'])) ?>" class="btn btn-outline"><?= icon('user-plus', 'size-4') ?> Individual</a>
        <a href="<?= e(url('staff.visitors.create', ['type' => 'institution'])) ?>" class="btn btn-brand"><?= icon('building-2', 'size-4') ?> Institution</a>
<?php endif ?>
<?php $this->stop() ?>

<form method="get" action="<?= e(url('staff.visitors.index')) ?>" class="card card-body mb-5 grid gap-3 md:grid-cols-[minmax(0,1fr)_200px_auto]" role="search">
    <input type="hidden" name="kyc" value="<?= e($filters['kyc']) ?>">
    <label class="relative">
        <span class="sr-only">Search</span>
        <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-muted"><?= icon('search', 'size-[18px]') ?></span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="CMN-KTR-I-2026-00001, name, mobile, email, PAN, GSTIN" class="input pl-10" autofocus>
    </label>
    <select name="type" class="input" aria-label="Visitor type">
        <option value="">All types</option>
        <?php foreach (CustomerType::cases() as $t): ?><option value="<?= e($t->value) ?>" <?= $filters['type'] === $t->value ? 'selected' : '' ?>><?= e($t->label()) ?></option><?php endforeach ?>
    </select>
    <div class="flex gap-2">
        <button type="submit" class="btn btn-brand flex-1"><?= icon('search', 'size-4') ?> Search</button>
        <?php if ($filters['q'] !== '' || $filters['type'] !== '' || $filters['kyc'] !== ''): ?><a href="<?= e(url('staff.visitors.index')) ?>" class="btn btn-ghost">Clear</a><?php endif ?>
    </div>
</form>

<?= $this->component('chips', ['items' => $chipItems, 'active' => $filters['kyc'], 'label' => 'KYC status', 'class' => 'mb-5']) ?>

<?= $this->component('table', [
    'caption' => 'Visitors',
    'rows' => $result['rows'],
    'empty' => $filters['q'] !== '' ? 'No visitor matches “' . $filters['q'] . '”.' : 'No visitors registered yet.',
    'columns' => [
        ['key' => 'name', 'label' => 'Visitor', 'html' => true, 'render' => function (array $r): string {
            $type = CustomerType::from((string) $r['type']);
            $sub = CustomerSubCategory::tryFrom((string) ($r['sub_category'] ?? ''));
            return '<a href="' . e(url('staff.visitors.show', ['ref' => Customer::ref($r)])) . '" class="group flex items-center gap-3">'
                . '<span class="grid size-9 shrink-0 place-items-center rounded-full ' . ($type === CustomerType::Individual ? 'bg-brand-50 text-brand-700' : 'bg-sky-50 text-sky-700') . '">' . icon($type === CustomerType::Individual ? 'user-round' : 'building-2', 'size-4') . '</span>'
                . '<span class="min-w-0"><span class="block font-semibold text-ink group-hover:text-brand-600">' . e($r['name']) . '</span>'
                . '<span class="block text-xs text-muted">' . e($type->label() . ($sub !== null ? ' · ' . $sub->label() : '') . ($r['registered_via'] === 'reception' ? ' · front desk' : '')) . '</span></span></a>';
        }],
        ['key' => 'unique_id', 'label' => 'Unique ID', 'html' => true, 'render' => fn (array $r): string => $r['unique_id'] !== null
            ? '<span class="font-mono text-xs font-semibold">' . e($r['unique_id']) . '</span>' : '<span class="text-xs text-muted">Not submitted</span>'],
        ['key' => 'mobile', 'label' => 'Contact', 'html' => true, 'render' => fn (array $r): string => '<span class="block">' . e(format_phone($r['mobile'] ?? '') ?: '—') . '</span><span class="block max-w-[14rem] truncate text-xs text-muted">' . e($r['email'] ?? '') . '</span>'],
        ['key' => 'ids', 'label' => 'ID on file', 'html' => true, 'render' => fn (array $r): string => '<span class="font-mono text-xs">'
            . e($r['pan'] ?? ($r['aadhaar_last4'] !== null ? AadhaarVault::mask($r['aadhaar_last4']) : ($r['passport_no'] ?? '—'))) . '</span>'],
        ['key' => 'kyc_status', 'label' => 'KYC', 'html' => true, 'render' => fn (array $r): string => $this->component('badge', ['label' => KycStatus::from((string) $r['kyc_status'])->label(), 'tone' => KycStatus::from((string) $r['kyc_status'])->tone(), 'dot' => true])],
        ['key' => 'created_at', 'label' => 'Registered', 'render' => fn (array $r): string => format_date((string) $r['created_at'], 'd M Y')],
    ],
]) ?>
<div class="mt-5">
    <?= $this->component('pagination', ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'], 'perPage' => $result['per_page'], 'route' => 'staff.visitors.index', 'query' => $filters]) ?>
</div>
