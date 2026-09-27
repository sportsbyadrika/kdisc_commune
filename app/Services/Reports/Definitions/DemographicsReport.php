<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Support\IndianStates;

/**
 * Who uses the centre: individual sub-categories and institution types, online vs reception registrations, home
 * state / nationality. Visitors registered in the period (default: all time).
 */
final class DemographicsReport extends Report
{
    public function __construct(private readonly Database $db)
    {
    }

    public function key(): string
    {
        return 'demographics';
    }

    public function title(): string
    {
        return 'Visitor demographics';
    }

    public function description(): string
    {
        return 'Individual categories, institution types, online vs reception registrations and home states.';
    }

    public function group(): string
    {
        return 'visitors';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function filters(): array
    {
        return [Filter::range('fy', 'Registered'), Filter::select('kyc', 'KYC', ['verified' => 'Verified only', 'submitted' => 'Submitted (any status)'], '')];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $where = 'DATE(c.created_at) BETWEEN ? AND ?';
        $bind = [$f->from(), $f->to()];
        if ($f->get('kyc') === 'verified') {
            $where .= " AND c.kyc_status = 'verified'";
        } elseif ($f->get('kyc') === 'submitted') {
            $where .= " AND c.kyc_status <> 'not_submitted'";
        }
        $all = (int) $this->db->scalar("SELECT COUNT(*) FROM customers c WHERE {$where}", $bind);
        $share = static fn (int $n) => $all > 0 ? round($n / $all, 4) : 0.0;

        $counts = [];
        foreach ($this->db->select(
            "SELECT c.type, c.sub_category, COUNT(*) AS n, SUM(c.registered_via = 'online') AS online, SUM(c.kyc_status = 'verified') AS verified,
                    SUM(EXISTS (SELECT 1 FROM bookings b WHERE b.customer_id = c.id AND b.status IN ('confirmed', 'active', 'completed'))) AS booked
             FROM customers c WHERE {$where} GROUP BY c.type, c.sub_category",
            $bind,
        ) as $r) {
            $counts[$r['type'] . '|' . $r['sub_category']] = $r;
        }
        $rows = [];
        foreach ([CustomerType::Individual, CustomerType::Institution] as $type) {
            foreach (CustomerSubCategory::optionsFor($type) as $value => $label) {
                $r = $counts[$type->value . '|' . $value] ?? null;
                $n = (int) ($r['n'] ?? 0);
                $rows[] = ['type' => $type->label(), 'label' => $label, 'visitors' => $n, 'online' => (int) ($r['online'] ?? 0), 'reception' => $n - (int) ($r['online'] ?? 0),
                    'verified' => (int) ($r['verified'] ?? 0), 'booked' => (int) ($r['booked'] ?? 0), 'share' => $share($n)];
            }
            $blank = $counts[$type->value . '|'] ?? null;
            if ($blank !== null) {
                $n = (int) $blank['n'];
                $rows[] = ['type' => $type->label(), 'label' => 'Not stated', 'visitors' => $n, 'online' => (int) $blank['online'], 'reception' => $n - (int) $blank['online'],
                    'verified' => (int) $blank['verified'], 'booked' => (int) $blank['booked'], 'share' => $share($n)];
            }
        }

        $channel = array_map(static fn (array $r) => [
            'type' => CustomerType::tryFrom((string) $r['type'])?->label() ?? (string) $r['type'],
            'channel' => $r['via'] === 'online' ? 'Online self-registration' : 'Reception (assisted)',
            'visitors' => (int) $r['n'], 'verified' => (int) $r['verified'], 'share' => $share((int) $r['n']),
        ], $this->db->select(
            "SELECT c.type, c.registered_via AS via, COUNT(*) AS n, SUM(c.kyc_status = 'verified') AS verified FROM customers c WHERE {$where} GROUP BY c.type, c.registered_via ORDER BY c.type, via",
            $bind,
        ));

        $places = array_map(static fn (array $r) => [
            'place' => $r['nationality'] !== null && $r['nationality'] !== 'Indian' ? 'Foreign national — ' . $r['nationality'] : (IndianStates::name($r['state_code'] !== null ? (string) $r['state_code'] : null) ?: 'Not stated'),
            'visitors' => (int) $r['n'], 'share' => $share((int) $r['n']),
        ], $this->db->select(
            "SELECT CASE WHEN c.nationality IS NOT NULL AND c.nationality <> 'Indian' THEN NULL ELSE c.state_code END AS state_code,
                    CASE WHEN c.nationality IS NOT NULL AND c.nationality <> 'Indian' THEN c.nationality ELSE 'Indian' END AS nationality, COUNT(*) AS n
             FROM customers c WHERE {$where} GROUP BY 1, 2 ORDER BY n DESC",
            $bind,
        ));

        $online = array_sum(array_map(static fn (array $r) => $r['channel'] === 'Online self-registration' ? $r['visitors'] : 0, $channel));
        return new ReportResult('Visitors by category', [
            Column::text('type', 'Type', 14), Column::text('label', 'Category'), Column::int('visitors', 'Visitors'), Column::int('online', 'Online'),
            Column::int('reception', 'Reception'), Column::int('verified', 'KYC verified'), Column::int('booked', 'With a booking'), Column::pct('share', 'Share'),
        ], $rows, 'Categories', ['share' => $all > 0 ? 1.0 : 0.0],
            notes: [sprintf('%d visitors registered in the period — %d online, %d at reception.', $all, $online, $all - $online)],
            chart: ['kind' => 'hbar-count', 'label' => 'Visitors', 'labels' => array_map(static fn (array $r) => $r['label'], $rows), 'values' => array_column($rows, 'visitors')],
            sheets: [
                new ReportResult('Registration channel', [
                    Column::text('type', 'Type', 14), Column::text('channel', 'Channel', 28), Column::int('visitors', 'Visitors'), Column::int('verified', 'KYC verified'), Column::pct('share', 'Share'),
                ], $channel, 'Channel', ['share' => $all > 0 ? 1.0 : 0.0]),
                new ReportResult('Home state / nationality', [
                    Column::text('place', 'State / nationality', 34), Column::int('visitors', 'Visitors'), Column::pct('share', 'Share'),
                ], $places, 'States', ['share' => $all > 0 ? 1.0 : 0.0]),
            ]);
    }
}
