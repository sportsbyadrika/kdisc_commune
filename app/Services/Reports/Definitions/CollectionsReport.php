<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Money received by payment mode (or kind), split into Finance-verified and still-to-verify. */
final class CollectionsReport extends Report
{
    public const GROUPS = ['mode' => 'Payment mode', 'kind' => 'Payment for', 'month' => 'Month'];

    public function __construct(private readonly Database $db)
    {
    }

    public function key(): string
    {
        return 'collections';
    }

    public function title(): string
    {
        return 'Collections';
    }

    public function description(): string
    {
        return 'Payments received by mode, purpose or month — verified by Finance vs awaiting verification.';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return 'wallet';
    }

    public function filters(): array
    {
        return [Filter::range('month'), Filter::select('group', 'Group by', self::GROUPS, 'mode')];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $by = $f->get('group', 'mode');
        $expr = match ($by) {
            'kind' => 'kind',
            'month' => "DATE_FORMAT(paid_on, '%Y-%m')",
            default => 'mode',
        };
        $rows = array_map(static fn (array $r) => [
            'label' => match ($by) {
                'kind' => PaymentKind::tryFrom((string) $r['grp'])?->label() ?? (string) $r['grp'],
                'month' => format_date($r['grp'] . '-01', 'M Y'),
                default => PaymentMode::tryFrom((string) $r['grp'])?->label() ?? (string) $r['grp'],
            },
            'payments' => (int) $r['n'], 'amount' => round((float) $r['amount'], 2),
            'verified' => round((float) $r['verified'], 2), 'pending' => round((float) $r['amount'] - (float) $r['verified'], 2),
        ], $this->db->select(
            "SELECT {$expr} AS grp, COUNT(*) AS n, SUM(amount) AS amount, SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) AS verified
             FROM payments WHERE status IN ('logged', 'verified') AND paid_on BETWEEN ? AND ? GROUP BY grp ORDER BY " . ($by === 'month' ? 'grp' : 'amount DESC'),
            [$f->from(), $f->to()],
        ));
        $total = array_sum(array_column($rows, 'amount'));
        $rows = array_map(static fn (array $r) => $r + ['share' => $total > 0 ? round($r['amount'] / $total, 4) : 0.0], $rows);
        return new ReportResult('Collections by ' . strtolower(self::GROUPS[$by]), [
            Column::text('label', self::GROUPS[$by]), Column::int('payments', 'Payments'), Column::money('amount', 'Received (₹)'),
            Column::money('verified', 'Verified (₹)'), Column::money('pending', 'To verify (₹)'), Column::pct('share', 'Share'),
        ], $rows, 'Collections', ['share' => $rows !== [] ? 1.0 : 0.0],
            notes: ['Logged and verified payments by payment date. Voided payments are excluded.'],
            chart: ['kind' => $by === 'month' ? 'bar-money' : 'hbar', 'label' => 'Received', 'labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'amount')]);
    }
}
