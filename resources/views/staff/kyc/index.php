<?php
/**
 * KYC verification queue.
 *
 * @var App\Core\Template $this
 * @var App\Enums\KycStatus $status
 * @var list<array<string, mixed>> $rows
 * @var array<string, int> $counts
 */
use App\Enums\CustomerType;
use App\Enums\KycStatus;

$this->layout('layouts/staff', ['breadcrumb' => [['Dashboard', url('staff.dashboard')], ['KYC verification']]]);
$ago = static function (?string $at): string {
    if ($at === null) {
        return '—';
    }
    $m = max(0, (int) floor((time() - strtotime($at)) / 60));
    return match (true) {
        $m < 1 => 'just now',
        $m < 60 => $m . ' min ago',
        $m < 1440 => floor($m / 60) . ' h ago',
        default => floor($m / 1440) . ' d ago',
    };
};
?>
<?= $this->component('chips', ['active' => $status->value, 'label' => 'KYC status', 'class' => 'mb-6', 'items' => array_map(
    static fn (KycStatus $k) => ['value' => $k->value, 'label' => $k === KycStatus::Pending ? 'Waiting' : $k->label(), 'count' => $counts[$k->value] ?? 0, 'href' => url('staff.kyc.index', ['status' => $k->value])],
    [KycStatus::Pending, KycStatus::Rejected, KycStatus::Verified],
)]) ?>

<?php if ($rows === [] && $status === KycStatus::Pending): ?>
    <?= $this->component('empty', ['icon' => 'badge-check', 'title' => 'All caught up', 'text' => 'No profiles are waiting for verification. New submissions appear here, oldest first.']) ?>
<?php else: ?>
    <?= $this->component('table', [
        'caption' => 'KYC queue',
        'rows' => $rows,
        'empty' => 'Nothing here.',
        'columns' => [
            ['key' => 'name', 'label' => 'Visitor', 'html' => true, 'render' => fn (array $r): string => '<a class="font-semibold hover:text-brand-600" href="' . e(url('staff.kyc.show', ['id' => $r['id']])) . '">' . e($r['name']) . '</a>'
                . '<span class="block text-xs text-muted">' . e(CustomerType::from((string) $r['type'])->label() . ' · ' . ($r['registered_via'] === 'reception' ? 'front desk' : 'online')) . '</span>'],
            ['key' => 'unique_id', 'label' => 'Unique ID', 'html' => true, 'render' => fn (array $r): string => '<span class="font-mono text-xs font-semibold">' . e($r['unique_id'] ?? '—') . '</span>'],
            ['key' => 'kyc_submitted_at', 'label' => $status === KycStatus::Pending ? 'Waiting' : 'Submitted', 'render' => fn (array $r): string => $ago($r['kyc_submitted_at'])],
            ['key' => 'documents', 'label' => 'Documents', 'align' => 'right', 'render' => fn (array $r): string => (string) $r['documents']],
            ['key' => 'action', 'label' => '', 'align' => 'right', 'html' => true, 'render' => fn (array $r): string => '<a href="' . e(url('staff.kyc.show', ['id' => $r['id']])) . '" class="btn btn-sm ' . ($status === KycStatus::Pending ? 'btn-brand' : 'btn-outline') . '">' . ($status === KycStatus::Pending ? 'Review' : 'Open') . icon('arrow-right', 'size-3.5') . '</a>'],
        ],
    ]) ?>
<?php endif ?>
