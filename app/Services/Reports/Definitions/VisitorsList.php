<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Enums\KycStatus;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Services\Visitors\VisitorDirectory;
use App\Support\IndianStates;

/**
 * Export of the staff visitor list with the page's own filters (q, type, kyc) — rows from VisitorDirectory::search().
 * Aadhaar is never exported (not even masked); PAN / GSTIN are business identifiers staff already see.
 */
final class VisitorsList extends Report
{
    public const MAX_ROWS = 10000;

    public function __construct(private readonly VisitorDirectory $directory)
    {
    }

    public function key(): string
    {
        return 'visitors';
    }

    public function title(): string
    {
        return 'Visitors';
    }

    public function description(): string
    {
        return 'Visitor directory with type, category, contact, KYC status and registration channel.';
    }

    public function group(): string
    {
        return 'lists';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function ability(): string
    {
        return 'visitors.view';
    }

    public function listed(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [Filter::search('q'), Filter::select('type', 'Type', CustomerType::options()), Filter::select('kyc', 'KYC', KycStatus::options())];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $rows = $this->directory->search(['q' => $f->get('q'), 'type' => $f->get('type'), 'kyc' => $f->get('kyc')], 1, self::MAX_ROWS)['rows'];
        $full = $this->detail(array_map(static fn (array $r) => (int) $r['id'], $rows));
        $rows = array_map(static fn (array $r) => [
            'unique_id' => $r['unique_id'] ?? '', 'name' => $r['name'], 'type' => CustomerType::tryFrom((string) $r['type'])?->label() ?? '',
            'category' => CustomerSubCategory::tryFrom((string) ($r['sub_category'] ?? ''))?->label() ?? '', 'email' => $r['email'], 'mobile' => $r['mobile'],
            'city' => $full[(int) $r['id']]['city'] ?? '', 'state' => IndianStates::name($full[(int) $r['id']]['state_code'] ?? null), 'pan' => $r['pan'] ?? '', 'gstin' => $r['gstin'] ?? '',
            'kyc' => KycStatus::tryFrom((string) $r['kyc_status'])?->label() ?? '', 'via' => $r['registered_via'] === 'online' ? 'Online' : 'Reception',
            'portal' => $r['account_id'] !== null ? 'Yes' : 'No', 'created_at' => $r['created_at'],
        ], $rows);
        return new ReportResult('Visitors', [
            Column::mono('unique_id', 'Unique ID', 22), Column::text('name', 'Name', 28), Column::text('type', 'Type', 12), Column::text('category', 'Category', 16),
            Column::text('email', 'Email', 30), Column::mono('mobile', 'Mobile', 16), Column::text('city', 'City', 16), Column::text('state', 'State', 16),
            Column::mono('pan', 'PAN', 12), Column::mono('gstin', 'GSTIN', 18), Column::text('kyc', 'KYC', 14), Column::text('via', 'Registered via', 14),
            Column::text('portal', 'Portal account', 10), Column::datetime('created_at', 'Registered on'),
        ], $rows, 'Visitors', withTotals: false);
    }

    public function fileStem(ReportFilters $filters): string
    {
        return 'visitors-' . $filters->today;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function detail(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (db()->select('SELECT id, city, state_code FROM customers WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['id']] = $r;
            }
        }
        return $out;
    }
}
