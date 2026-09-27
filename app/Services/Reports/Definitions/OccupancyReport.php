<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\OccupancyService;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Occupancy by floor / space type / seat / day: seat-days occupied vs available (OccupancyService rules). */
final class OccupancyReport extends Report
{
    public const GROUPS = ['floor_category' => 'Floor × space type', 'category' => 'Space type', 'floor' => 'Floor', 'unit' => 'Seat / cabin', 'day' => 'Day'];

    public function __construct(private readonly OccupancyService $occupancy)
    {
    }

    public function key(): string
    {
        return 'occupancy';
    }

    public function title(): string
    {
        return 'Occupancy';
    }

    public function description(): string
    {
        return 'Seat-days occupied vs available by floor, space type, seat or day, with the daily trend.';
    }

    public function group(): string
    {
        return 'operations';
    }

    public function icon(): string
    {
        return 'armchair';
    }

    public function filters(): array
    {
        return [
            Filter::range('month'),
            Filter::select('group', 'Group by', self::GROUPS, 'floor_category'),
            Filter::select('floor', 'Floor', $this->occupancy->floors()),
            Filter::select('category', 'Space type', $this->occupancy->categories()),
        ];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $data = $this->occupancy->compute($f->from(), $f->to(), $f->get('floor'), $f->get('category'));
        $by = $f->get('group', 'floor_category');
        $pctTotal = ['pct' => $data['pct']];
        $chart = [
            'kind' => 'line-pct',
            'label' => 'Daily occupancy',
            'labels' => array_map(static fn (array $d) => format_date($d['date'], 'd M'), $data['daily']),
            'values' => array_map(static fn (array $d) => round($d['pct'] * 100, 1), $data['daily']),
        ];
        $notes = ['Seat-days = seats × days. Committed bookings only (approved, confirmed, active, completed); the conference room counts booked hours ÷ opening hours; blocked days are excluded from capacity.'];

        if ($by === 'day') {
            $rows = array_map(static fn (array $d) => $d + ['free' => round($d['capacity'] - $d['occupied'], 2), 'weekday' => format_date($d['date'], 'D')], $data['daily']);
            return new ReportResult('Occupancy by day', [
                Column::date('date', 'Date'), Column::text('weekday', 'Day', 8), Column::number('capacity', 'Seat-days available'),
                Column::number('occupied', 'Seat-days occupied'), Column::number('free', 'Seat-days free'), Column::pct('pct', 'Occupancy'),
            ], $rows, 'Occupancy by day', $pctTotal, notes: $notes, chart: $chart);
        }
        if ($by === 'unit') {
            $rows = array_map(static fn (array $u) => $u + ['free' => round($u['capacity'] - $u['occupied'], 2), 'state' => $u['blocked'] ? 'Blocked' : ($u['blocked_days'] > 0 ? 'Blocked ' . $u['blocked_days'] . ' d' : '')], $data['units']);
            return new ReportResult('Occupancy by seat / cabin', [
                Column::mono('code', 'Seat'), Column::text('floor', 'Floor'), Column::text('category_name', 'Space type'), Column::int('seats', 'Seats'),
                Column::number('capacity', 'Seat-days available'), Column::number('occupied', 'Seat-days occupied'), Column::number('free', 'Seat-days free'),
                Column::pct('pct', 'Occupancy'), Column::text('state', 'Note', 14),
            ], $rows, 'Occupancy by seat', $pctTotal, notes: $notes, chart: $chart);
        }
        $rows = OccupancyService::group($data['units'], $by);
        $cols = [];
        if ($by !== 'category') {
            $cols[] = Column::text('floor', 'Floor');
        }
        if ($by !== 'floor') {
            $cols[] = Column::text('category', 'Space type');
        }
        array_push($cols, Column::int('units', 'Units'), Column::int('seats', 'Seats'), Column::number('capacity', 'Seat-days available'),
            Column::number('occupied', 'Seat-days occupied'), Column::number('free', 'Seat-days free'), Column::pct('pct', 'Occupancy'));
        return new ReportResult('Occupancy by ' . strtolower(self::GROUPS[$by] ?? 'floor'), $cols, $rows, 'Occupancy', $pctTotal, notes: $notes, chart: $chart);
    }
}
