<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Enums\CustomerType;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/**
 * Registration → KYC → booking funnel for visitors registered in the period, split by channel (online self sign-up
 * vs reception-assisted), with the average time from submission to verification.
 */
final class KycFunnelReport extends Report
{
    public function __construct(private readonly Database $db)
    {
    }

    public function key(): string
    {
        return 'kyc-funnel';
    }

    public function title(): string
    {
        return 'KYC funnel';
    }

    public function description(): string
    {
        return 'Registered → submitted → verified → booked, online vs reception, with time to verify.';
    }

    public function group(): string
    {
        return 'visitors';
    }

    public function icon(): string
    {
        return 'shield-check';
    }

    public function filters(): array
    {
        return [Filter::range('fy', 'Registered'), Filter::select('type', 'Visitor type', CustomerType::options())];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $where = 'DATE(c.created_at) BETWEEN ? AND ?';
        $bind = [$f->from(), $f->to()];
        if ($f->get('type') !== '') {
            $where .= ' AND c.type = ?';
            $bind[] = $f->get('type');
        }
        $stages = [
            'registered' => ['Registered', '1 = 1'],
            'submitted' => ['Profile submitted (Unique ID issued)', "c.kyc_status <> 'not_submitted'"],
            'pending' => ['   awaiting verification', "c.kyc_status = 'pending'"],
            'rejected' => ['   rejected', "c.kyc_status = 'rejected'"],
            'verified' => ['KYC verified', "c.kyc_status = 'verified'"],
            'requested' => ['Made a booking request', 'EXISTS (SELECT 1 FROM bookings b WHERE b.customer_id = c.id)'],
            'booked' => ['Has a confirmed booking', "EXISTS (SELECT 1 FROM bookings b WHERE b.customer_id = c.id AND b.status IN ('confirmed', 'active', 'completed'))"],
        ];
        $select = [];
        foreach ($stages as $k => [, $cond]) {
            $select[] = "SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) AS {$k}";
        }
        $by = [];
        foreach ($this->db->select('SELECT c.registered_via AS via, ' . implode(', ', $select) . " FROM customers c WHERE {$where} GROUP BY c.registered_via", $bind) as $r) {
            $by[(string) $r['via']] = $r;
        }
        $total = static fn (string $k) => (int) ($by['online'][$k] ?? 0) + (int) ($by['reception'][$k] ?? 0);
        $registered = max(1, $total('registered'));
        $rows = [];
        foreach ($stages as $k => [$label]) {
            $rows[] = [
                'stage' => $label, 'online' => (int) ($by['online'][$k] ?? 0), 'reception' => (int) ($by['reception'][$k] ?? 0), 'total' => $total($k),
                'share' => round($total($k) / $registered, 4),
            ];
        }
        $avg = $this->db->first(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, c.kyc_submitted_at, c.kyc_verified_at)) AS h, COUNT(*) AS n FROM customers c
             WHERE {$where} AND c.kyc_status = 'verified' AND c.kyc_submitted_at IS NOT NULL AND c.kyc_verified_at IS NOT NULL",
            $bind,
        );
        $hours = $avg !== null && $avg['h'] !== null ? (float) $avg['h'] : null;
        $notes = ['Visitors registered in the period, by where they registered. Indented rows split "submitted".'];
        if ($hours !== null) {
            $notes[] = sprintf('Average time from submission to verification: %s (%d verified).', $hours < 48 ? round($hours, 1) . ' hours' : round($hours / 24, 1) . ' days', (int) $avg['n']);
        }
        return new ReportResult('KYC funnel', [
            Column::text('stage', 'Stage', 38), Column::int('online', 'Online', false), Column::int('reception', 'Reception', false), Column::int('total', 'Total', false), Column::pct('share', '% of registered'),
        ], $rows, 'KYC funnel', withTotals: false, notes: $notes,
            chart: ['kind' => 'funnel', 'label' => 'Visitors', 'labels' => array_map(static fn (array $r) => trim($r['stage']), array_values(array_filter($rows, static fn (array $r) => !str_starts_with($r['stage'], ' ')))),
                'values' => array_column(array_values(array_filter($rows, static fn (array $r) => !str_starts_with($r['stage'], ' '))), 'total')]);
    }
}
