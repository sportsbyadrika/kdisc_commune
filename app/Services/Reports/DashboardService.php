<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Core\Database;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\FinancialYear;
use App\Services\Reports\Definitions\DuesAgeingReport;
use App\Services\Reports\Definitions\RenewalsReport;
use App\Services\Space\BookingPeriod;
use App\Services\Space\FloorMapService;
use App\Services\Space\MiniMapPresenter;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Payloads for the State Admin dashboard (read-only, spec 6.5) and the Centre Manager "insights" section: KPI tiles,
 * monthly revenue / collections and occupancy trends, payment status, per-floor occupancy heat-maps and the
 * category breakdown. Pure composition of FinanceReportService, OccupancyService and the report definitions, so the
 * dashboard shows the same numbers as the reports.
 */
final class DashboardService
{
    public const PERIODS = ['month' => 'This month', 'last-month' => 'Last month', '3m' => 'Last 3 months', '6m' => 'Last 6 months', 'fy' => 'This FY', 'last-fy' => 'Last FY'];

    /** Heat-map classes: upper bound (inclusive, %) => label. 0 % gets its own "unused" class. */
    public const HEAT_BINS = [20 => '1–20%', 40 => '21–40%', 60 => '41–60%', 80 => '61–80%', 100 => '81–100%'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly OccupancyService $occupancy,
        private readonly FinanceReportService $finance,
        private readonly FloorMapService $maps,
        private readonly MiniMapPresenter $mini,
        private readonly DuesAgeingReport $ageing,
        private readonly RenewalsReport $renewals,
    ) {
    }

    /** @return array{0: string, 1: string, 2: string} preset, from, to */
    public function period(string $preset): array
    {
        $preset = array_key_exists($preset, self::PERIODS) ? $preset : 'month';
        [$from, $to] = ReportFilters::preset($preset, $this->clock->today());
        return [$preset, $from, $to];
    }

    /** @return array<string, mixed> */
    public function stateAdmin(string $preset): array
    {
        [$preset, $from, $to] = $this->period($preset);
        $today = $this->clock->today();
        $fy = FinancialYear::of($today);
        [$fyStart] = FinancialYear::range($fy);
        $monthStart = substr($today, 0, 8) . '01';
        $now = $this->occupancy->compute($today, $today);
        $period = $this->occupancy->compute($from, $to);
        $outstanding = $this->finance->outstandingTotals();
        return [
            'preset' => $preset,
            'periods' => self::PERIODS,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'fy' => $fy,
            'kpi' => [
                'revenue_mtd' => $this->finance->gst($monthStart, $today)['taxable'],
                'revenue_fytd' => $this->finance->gst($fyStart, $today)['taxable'],
                'collected' => $this->finance->collected($from, $to),
                'dues' => $outstanding,
                'active_bookings' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed', 'active')"),
                'active_seats' => (int) $this->db->scalar("SELECT COALESCE(SUM(seats_count), 0) FROM bookings WHERE status IN ('confirmed', 'active')"),
                'occupancy_today' => $now['pct'],
                'occupancy_period' => $period['pct'],
            ],
            'seatsByCategory' => $this->seatsByCategory($now['units']),
            'trend' => $this->trend(12),
            'payments' => $this->paymentStatus($from, $to),
            'categories' => $this->categoryBreakdown($period['units'], $from, $to),
            'heatmaps' => $this->heatmaps($period['units'], $from, $to),
            'daily' => $this->dailyChart($period['daily']),
        ];
    }

    /** @return array<string, mixed> Centre Manager insights below the front-desk board */
    public function centreManager(string $preset): array
    {
        [$preset, $from, $to] = $this->period($preset);
        $today = $this->clock->today();
        $period = $this->occupancy->compute($from, $to);
        $renewals = $this->renewals->rows($today, 30);
        $ageing = $this->ageing->rows($today);
        $buckets = [];
        foreach (AgeingCalculator::BUCKETS as $k => $label) {
            $buckets[] = ['key' => $k, 'label' => $label, 'amount' => round(array_sum(array_column($ageing, $k)), 2), 'count' => count(array_filter($ageing, static fn (array $r) => $r[$k] > 0))];
        }
        $kyc = (array) $this->db->first(
            "SELECT COUNT(*) AS pending, MIN(kyc_submitted_at) AS oldest FROM customers WHERE kyc_status = 'pending'",
        );
        return [
            'preset' => $preset,
            'periods' => self::PERIODS,
            'from' => $from,
            'to' => $to,
            'occupancy' => $period['pct'],
            'heatmaps' => $this->heatmaps($period['units'], $from, $to),
            'renewals' => [
                'rows' => array_slice($renewals, 0, 8),
                'total' => count($renewals),
                'open' => count(array_filter($renewals, static fn (array $r) => $r['renewal_no'] === null)),
                'renewed' => count(array_filter($renewals, static fn (array $r) => $r['renewal_no'] !== null)),
                'urgent' => count(array_filter($renewals, static fn (array $r) => $r['renewal_no'] === null && $r['days_left'] <= 7)),
            ],
            'kyc' => [
                'pending' => (int) ($kyc['pending'] ?? 0),
                'oldest_days' => !empty($kyc['oldest']) ? (int) (new DateTimeImmutable((string) $kyc['oldest']))->diff(new DateTimeImmutable($today))->days : 0,
                'not_submitted' => (int) $this->db->scalar("SELECT COUNT(*) FROM customers WHERE kyc_status = 'not_submitted'"),
            ],
            'conversion' => $this->conversion((new DateTimeImmutable($today))->modify('-89 days')->format('Y-m-d'), $today),
            'ageing' => ['buckets' => $buckets, 'total' => round(array_sum(array_column($ageing, 'total')), 2), 'bookings' => count($ageing)],
        ];
    }

    /**
     * Request → approval → confirmation funnel of bookings requested in the window.
     *
     * @return array{requested: int, approved: int, confirmed: int, declined: int, open: int, rate: float, online: int, reception: int}
     */
    public function conversion(string $from, string $to): array
    {
        $r = (array) $this->db->first(
            "SELECT COUNT(*) AS n,
                    SUM(status IN ('approved', 'confirmed', 'active', 'completed')) AS approved,
                    SUM(status IN ('confirmed', 'active', 'completed')) AS confirmed,
                    SUM(status IN ('rejected', 'cancelled')) AS declined,
                    SUM(status = 'requested') AS open,
                    SUM(source = 'online') AS online, SUM(source = 'reception') AS reception
             FROM bookings WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY",
            [$from, $to],
        );
        $n = (int) ($r['n'] ?? 0);
        return [
            'requested' => $n, 'approved' => (int) ($r['approved'] ?? 0), 'confirmed' => (int) ($r['confirmed'] ?? 0), 'declined' => (int) ($r['declined'] ?? 0),
            'open' => (int) ($r['open'] ?? 0), 'online' => (int) ($r['online'] ?? 0), 'reception' => (int) ($r['reception'] ?? 0),
            'rate' => $n > 0 ? round((int) ($r['confirmed'] ?? 0) / $n, 4) : 0.0,
        ];
    }

    /**
     * Per-floor heat-map configs for resources/js/dashboards.js `heatMap` (MiniMapPresenter geometry + occupancy per
     * seat_key).
     *
     * @param list<array<string, mixed>> $units OccupancyService::compute()['units']
     * @return list<array<string, mixed>>
     */
    public function heatmaps(array $units, string $from, string $to): array
    {
        $byFloor = [];
        foreach ($units as $u) {
            $byFloor[(int) $u['floor_id']][(string) $u['key']] = ['pct' => round((float) $u['pct'] * 100, 1), 'occ' => round((float) $u['occupied'], 1), 'cap' => round((float) $u['capacity'], 1), 'blocked' => (bool) $u['blocked']];
        }
        $out = [];
        $period = BookingPeriod::days($this->clock->today(), $this->clock->today());
        foreach ($this->maps->floors() as $f) {
            $floor = $this->maps->findFloor((string) $f['slug']);
            if ($floor === null) {
                continue;
            }
            $cfg = $this->mini->floor($floor, $period, [], ['mode' => 'heat']);
            // geometry only — occupancy comes from the period, not today's live status
            $cfg['seats'] = array_map(static fn (array $s) => array_diff_key($s, ['occupant' => 1, 'status' => 1, 'mine' => 1]), $cfg['seats']);
            unset($cfg['stats']);
            $floorUnits = array_filter($units, static fn (array $u) => (int) $u['floor_id'] === (int) $floor['id']);
            $cap = array_sum(array_column($floorUnits, 'capacity'));
            $occ = array_sum(array_column($floorUnits, 'occupied'));
            $cfg['heat'] = (object) ($byFloor[(int) $floor['id']] ?? []);
            $cfg['bins'] = array_keys(self::HEAT_BINS);
            $cfg['summary'] = ['pct' => $cap > 0 ? round($occ / $cap * 100, 1) : 0.0, 'units' => count($floorUnits), 'from' => $from, 'to' => $to];
            $out[] = $cfg;
        }
        return $out;
    }

    /**
     * Seats by category today: seats, occupied, free, blocked.
     *
     * @param list<array<string, mixed>> $units compute(today, today)['units']
     * @return list<array<string, mixed>>
     */
    private function seatsByCategory(array $units): array
    {
        $out = [];
        foreach (OccupancyService::group($units, 'category') as $g) {
            $blocked = 0;
            foreach ($units as $u) {
                if ($u['category_name'] === $g['category'] && !empty($u['blocked'])) {
                    $blocked += (int) $u['seats'];
                }
            }
            $out[] = ['label' => $g['category'], 'code' => $g['category_code'], 'colour' => $g['colour'], 'seats' => (int) $g['seats'], 'occupied' => round((float) $g['occupied'], 1),
                'blocked' => $blocked, 'free' => round(max(0, (float) $g['capacity'] - (float) $g['occupied']), 1), 'pct' => (float) $g['pct']];
        }
        return $out;
    }

    /**
     * Last $months months: net taxable invoiced, collections, occupancy %.
     *
     * @return array{labels: list<string>, revenue: list<float>, collected: list<float>, occupancy: list<float>, current: int}
     */
    public function trend(int $months): array
    {
        $today = new DateTimeImmutable($this->clock->today());
        $start = $today->modify('first day of -' . ($months - 1) . ' months');
        $occ = $this->occupancy->compute($start->format('Y-m-d'), $today->format('Y-m-t'));
        $byMonth = [];
        foreach ($occ['daily'] as $d) {
            $m = substr($d['date'], 0, 7);
            $byMonth[$m] ??= [0.0, 0.0];
            $byMonth[$m][0] += $d['capacity'];
            $byMonth[$m][1] += $d['occupied'];
        }
        $out = ['labels' => [], 'revenue' => [], 'collected' => [], 'occupancy' => [], 'current' => $months - 1];
        for ($m = $start; $m <= $today; $m = $m->modify('first day of next month')) {
            $from = $m->format('Y-m-01');
            $to = $m->format('Y-m-t');
            $out['labels'][] = $m->format('M y');
            $out['revenue'][] = $this->finance->gst($from, $to)['taxable'];
            $out['collected'][] = $this->finance->collected($from, $to)['total'];
            [$cap, $used] = $byMonth[$m->format('Y-m')] ?? [0.0, 0.0];
            $out['occupancy'][] = $cap > 0 ? round($used / $cap * 100, 1) : 0.0;
        }
        return $out;
    }

    /**
     * Payment status in the period (by payment date) + what is still awaited on approved bookings.
     *
     * @return array<string, mixed>
     */
    private function paymentStatus(string $from, string $to): array
    {
        $rows = [];
        foreach ($this->db->select(
            "SELECT CASE WHEN status = 'logged' AND queried_at IS NOT NULL AND query_resolved_at IS NULL THEN 'queried' ELSE status END AS st, COUNT(*) AS n, SUM(amount) AS amount
             FROM payments WHERE paid_on BETWEEN ? AND ? GROUP BY st",
            [$from, $to],
        ) as $r) {
            $rows[(string) $r['st']] = ['count' => (int) $r['n'], 'amount' => round((float) $r['amount'], 2)];
        }
        $awaiting = (array) $this->db->first("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN payment_rule = 'advance' THEN grand_total ELSE deposit_amount END), 0) AS amount FROM bookings WHERE status = 'approved'");
        $items = [
            ['key' => 'verified', 'label' => 'Verified by Finance', 'tone' => 'success', 'icon' => 'circle-check'],
            ['key' => 'logged', 'label' => 'Logged — awaiting verification', 'tone' => 'warning', 'icon' => 'hourglass'],
            ['key' => 'queried', 'label' => 'Queried by Finance', 'tone' => 'info', 'icon' => 'message-circle-question'],
            ['key' => 'void', 'label' => 'Voided', 'tone' => 'neutral', 'icon' => 'circle-x'],
        ];
        $total = array_sum(array_map(static fn (array $r) => $r['amount'], array_intersect_key($rows, ['verified' => 1, 'logged' => 1, 'queried' => 1])));
        return [
            'items' => array_map(static fn (array $i) => $i + ($rows[$i['key']] ?? ['count' => 0, 'amount' => 0.0]), $items),
            'received' => round($total, 2),
            'awaiting' => ['count' => (int) ($awaiting['n'] ?? 0), 'amount' => round((float) ($awaiting['amount'] ?? 0), 2)],
        ];
    }

    /**
     * Occupancy + revenue per space type for the period.
     *
     * @param list<array<string, mixed>> $units
     * @return list<array<string, mixed>>
     */
    private function categoryBreakdown(array $units, string $from, string $to): array
    {
        $revenue = [];
        foreach ($this->finance->revenueByCategory($from, $to) as $r) {
            $revenue[$r['label']] = $r['amount'];
        }
        $bookings = [];
        foreach ($this->db->select(
            "SELECT sc.name, COUNT(*) AS n FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id
             WHERE b.status IN ('approved', 'confirmed', 'active', 'completed') AND b.start_date <= ? AND b.end_date >= ? GROUP BY sc.name",
            [$to, $from],
        ) as $r) {
            $bookings[(string) $r['name']] = (int) $r['n'];
        }
        return array_map(static fn (array $g) => $g + ['revenue' => $revenue[$g['category']] ?? 0.0, 'bookings' => $bookings[$g['category']] ?? 0], OccupancyService::group($units, 'category'));
    }

    /**
     * @param list<array{date: string, capacity: float, occupied: float, pct: float}> $daily
     * @return array<string, mixed>
     */
    private function dailyChart(array $daily): array
    {
        if (count($daily) > 120) { // long periods: weekly points keep the line readable
            $weeks = [];
            foreach ($daily as $d) {
                $k = (new DateTimeImmutable($d['date']))->modify('monday this week')->format('Y-m-d');
                $weeks[$k] ??= [0.0, 0.0];
                $weeks[$k][0] += $d['capacity'];
                $weeks[$k][1] += $d['occupied'];
            }
            return ['kind' => 'line-pct', 'label' => 'Weekly occupancy', 'labels' => array_map(static fn (string $k) => format_date($k, 'd M'), array_keys($weeks)),
                'values' => array_map(static fn (array $w) => $w[0] > 0 ? round($w[1] / $w[0] * 100, 1) : 0.0, array_values($weeks))];
        }
        return ['kind' => 'line-pct', 'label' => 'Daily occupancy', 'labels' => array_map(static fn (array $d) => format_date($d['date'], 'd M'), $daily),
            'values' => array_map(static fn (array $d) => round($d['pct'] * 100, 1), $daily)];
    }
}
