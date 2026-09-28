<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Services\Bookings\BookingDirectory;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\OccupancyService;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Export of the bookings console (tab + filters) — rows from BookingDirectory::console(). */
final class BookingsList extends Report
{
    public function __construct(private readonly BookingDirectory $directory, private readonly OccupancyService $occupancy)
    {
    }

    public function key(): string
    {
        return 'bookings-list';
    }

    public function title(): string
    {
        return 'Bookings';
    }

    public function description(): string
    {
        return 'Bookings console export: visitor, seats, dates, status, source and value.';
    }

    public function group(): string
    {
        return 'lists';
    }

    public function icon(): string
    {
        return 'calendar-check';
    }

    public function ability(): string
    {
        return 'bookings.view';
    }

    public function listed(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [
            Filter::select('tab', 'Tab', array_map(static fn (array $t) => $t[0], BookingDirectory::TABS), 'requests'),
            Filter::search('q'),
            Filter::select('category', 'Space type', array_change_key_case($this->occupancy->categories(), CASE_LOWER)),
            Filter::select('floor', 'Floor', $this->occupancy->floors()),
            Filter::select('source', 'Source', BookingSource::options()),
            Filter::select('within', 'Ending within', ['7' => '7 days', '15' => '15 days', '30' => '30 days'], '30'),
            Filter::date('from', 'From'),
            Filter::date('to', 'To'),
        ];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $filters = array_intersect_key($f->all(), array_flip(['q', 'category', 'floor', 'source', 'within', 'from', 'to']));
        $rows = $this->directory->console($f->get('tab', 'requests'), $filters, $f->today, 1, VisitorsList::MAX_ROWS)['rows'];
        $rows = array_map(static fn (array $b) => $b + [
            'status_label' => BookingStatus::tryFrom((string) $b['status'])?->label() ?? (string) $b['status'],
            'source_label' => BookingSource::tryFrom((string) $b['source'])?->label() ?? (string) $b['source'],
            'times' => $b['start_time'] !== null ? substr((string) $b['start_time'], 0, 5) . '–' . substr((string) $b['end_time'], 0, 5) : '',
        ], $rows);
        return new ReportResult('Bookings — ' . (BookingDirectory::TABS[$f->get('tab', 'requests')][0] ?? ''), [
            Column::mono('booking_no', 'Booking', 16), Column::text('customer_name', 'Visitor', 26), Column::mono('unique_id', 'Unique ID', 22), Column::text('category_name', 'Space type', 16),
            Column::mono('seat_codes', 'Seats', 20), Column::text('floor_name', 'Floor', 13), Column::date('start_date', 'From'), Column::date('end_date', 'To'), Column::text('times', 'Hours', 12),
            Column::int('seats_count', 'Seats'), Column::text('status_label', 'Status', 12), Column::text('source_label', 'Source', 11),
            Column::money('grand_total', 'Total (₹)'), Column::money('deposit_amount', 'Deposit (₹)'), Column::datetime('created_at', 'Requested on'),
        ], $rows, 'Bookings');
    }

    public function fileStem(ReportFilters $filters): string
    {
        return 'bookings-' . $filters->get('tab', 'requests') . '-' . $filters->today;
    }
}
