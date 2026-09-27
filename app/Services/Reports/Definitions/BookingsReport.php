<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\OccupancyService;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Booking counts, seats and value by status / source / space type / month. */
final class BookingsReport extends Report
{
    public const GROUPS = ['status' => 'Status', 'source' => 'Source', 'category' => 'Space type', 'month' => 'Month'];

    public function __construct(private readonly Database $db, private readonly OccupancyService $occupancy)
    {
    }

    public function key(): string
    {
        return 'bookings';
    }

    public function title(): string
    {
        return 'Bookings summary';
    }

    public function description(): string
    {
        return 'Bookings, seats and booking value by status, source (online / reception), space type or month.';
    }

    public function group(): string
    {
        return 'operations';
    }

    public function icon(): string
    {
        return 'calendar-check';
    }

    public function filters(): array
    {
        return [
            Filter::range('3m'),
            Filter::select('basis', 'Dates by', ['created' => 'Request date', 'start' => 'Start date'], 'created'),
            Filter::select('group', 'Group by', self::GROUPS, 'status'),
            Filter::select('category', 'Space type', $this->occupancy->categories()),
            Filter::select('source', 'Source', BookingSource::options()),
        ];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $by = $f->get('group', 'status');
        $dateCol = $f->get('basis') === 'start' ? 'b.start_date' : 'DATE(b.created_at)';
        $where = ["{$dateCol} BETWEEN ? AND ?"];
        $bind = [$f->from(), $f->to()];
        if ($f->get('category') !== '') {
            $where[] = 'sc.code = ?';
            $bind[] = $f->get('category');
        }
        if ($f->get('source') !== '') {
            $where[] = 'b.source = ?';
            $bind[] = $f->get('source');
        }
        $groupExpr = match ($by) {
            'source' => 'b.source',
            'category' => 'sc.name',
            'month' => "DATE_FORMAT({$dateCol}, '%Y-%m')",
            default => 'b.status',
        };
        $rows = $this->db->select(
            "SELECT {$groupExpr} AS grp, MIN(sc.sort_order) AS ord, COUNT(*) AS bookings, SUM(b.seats_count) AS seats, SUM(b.grand_total) AS value,
                    SUM(CASE WHEN b.status IN ('confirmed', 'active', 'completed') THEN 1 ELSE 0 END) AS confirmed,
                    SUM(CASE WHEN b.status IN ('cancelled', 'rejected') THEN 1 ELSE 0 END) AS lost
             FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id
             WHERE " . implode(' AND ', $where) . " GROUP BY {$groupExpr} ORDER BY " . ($by === 'category' ? 'ord' : 'grp'),
            $bind,
        );
        $statusOrder = array_flip(BookingStatus::values());
        if ($by === 'status') {
            usort($rows, static fn (array $a, array $b) => ($statusOrder[$a['grp']] ?? 99) <=> ($statusOrder[$b['grp']] ?? 99));
        }
        $out = array_map(static fn (array $r) => [
            'label' => match ($by) {
                'status' => BookingStatus::tryFrom((string) $r['grp'])?->label() ?? (string) $r['grp'],
                'source' => BookingSource::tryFrom((string) $r['grp'])?->label() ?? (string) $r['grp'],
                'month' => format_date($r['grp'] . '-01', 'M Y'),
                default => (string) $r['grp'],
            },
            'bookings' => (int) $r['bookings'], 'seats' => (int) $r['seats'], 'value' => round((float) $r['value'], 2),
            'confirmed' => (int) $r['confirmed'], 'lost' => (int) $r['lost'],
            'avg' => (int) $r['bookings'] > 0 ? round((float) $r['value'] / (int) $r['bookings'], 2) : 0.0,
            'conversion' => (int) $r['bookings'] > 0 ? round((int) $r['confirmed'] / (int) $r['bookings'], 4) : 0.0,
        ], $rows);
        $n = array_sum(array_column($out, 'bookings'));
        $conf = array_sum(array_column($out, 'confirmed'));
        return new ReportResult('Bookings by ' . strtolower(self::GROUPS[$by]), [
            Column::text('label', self::GROUPS[$by]), Column::int('bookings', 'Bookings'), Column::int('seats', 'Seats'),
            Column::int('confirmed', 'Confirmed or later'), Column::int('lost', 'Cancelled / rejected'), Column::pct('conversion', 'Confirmed %'),
            Column::money('value', 'Booking value (₹)'), Column::money('avg', 'Average (₹)', false),
        ], $out, 'Bookings', ['conversion' => $n > 0 ? round($conf / $n, 4) : 0.0, 'avg' => $n > 0 ? round(array_sum(array_column($out, 'value')) / $n, 2) : 0.0],
            notes: ['Booking value = grand total incl. GST as quoted. "Confirmed or later" = confirmed, active or completed.'],
            chart: ['kind' => 'bar-count', 'label' => 'Bookings', 'labels' => array_column($out, 'label'), 'values' => array_column($out, 'bookings')]);
    }
}
