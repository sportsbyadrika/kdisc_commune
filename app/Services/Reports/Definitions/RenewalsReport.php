<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use DateTimeImmutable;

/**
 * Bookings ending within N days (confirmed / active, day-based) and whether a renewal exists — the same rule as the
 * bookings console "Renewals due" tab (BookingDirectory), plus already-renewed ones for the pipeline view.
 */
final class RenewalsReport extends Report
{
    public const WINDOWS = ['7' => 'Next 7 days', '15' => 'Next 15 days', '30' => 'Next 30 days', '60' => 'Next 60 days', '90' => 'Next 90 days'];

    public function __construct(private readonly Database $db)
    {
    }

    public function key(): string
    {
        return 'renewals';
    }

    public function title(): string
    {
        return 'Renewals due';
    }

    public function description(): string
    {
        return 'Bookings ending soon with their renewal state — the renewals pipeline.';
    }

    public function group(): string
    {
        return 'operations';
    }

    public function icon(): string
    {
        return 'refresh-cw';
    }

    public function filters(): array
    {
        return [
            Filter::select('within', 'Ending', self::WINDOWS, '30'),
            Filter::select('state', 'Renewal', ['open' => 'Not renewed yet', 'renewed' => 'Renewed', 'all' => 'All'], 'all'),
        ];
    }

    /**
     * Pipeline rows (also used by the Centre Manager dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $today, int $within): array
    {
        $to = (new DateTimeImmutable($today))->modify("+{$within} days")->format('Y-m-d');
        return array_map(static function (array $r) use ($today): array {
            $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable((string) $r['end_date']))->format('%r%a');
            $state = $r['renewal_no'] !== null ? 'Renewed · ' . (BookingStatus::tryFrom((string) $r['renewal_status'])?->label() ?? '') : ($days <= 7 ? 'Not renewed — urgent' : 'Not renewed');
            return $r + ['days_left' => $days, 'state' => $state, 'status_label' => BookingStatus::from((string) $r['status'])->label()];
        }, $this->db->select(
            "SELECT b.booking_no, b.status, b.start_date, b.end_date, b.seats_count, b.grand_total, sc.name AS category, c.name AS customer_name, c.unique_id, c.mobile,
                    (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = b.id AND bs.transferred_to_id IS NULL) AS seats,
                    r.booking_no AS renewal_no, r.status AS renewal_status
             FROM bookings b
             JOIN customers c ON c.id = b.customer_id
             JOIN seat_categories sc ON sc.id = b.seat_category_id
             LEFT JOIN bookings r ON r.id = (SELECT MAX(x.id) FROM bookings x WHERE x.renewed_from_id = b.id AND x.status NOT IN ('cancelled', 'rejected'))
             WHERE b.status IN ('confirmed', 'active') AND b.start_time IS NULL AND b.end_date BETWEEN ? AND ?
             ORDER BY b.end_date, b.id",
            [$today, $to],
        ));
    }

    public function run(ReportFilters $f): ReportResult
    {
        $rows = $this->rows($f->today, (int) $f->get('within', '30'));
        $state = $f->get('state', 'all');
        if ($state !== 'all') {
            $rows = array_values(array_filter($rows, static fn (array $r) => ($r['renewal_no'] !== null) === ($state === 'renewed')));
        }
        return new ReportResult('Renewals due — ' . strtolower(self::WINDOWS[$f->get('within', '30')] ?? ''), [
            Column::mono('booking_no', 'Booking'), Column::text('customer_name', 'Visitor'), Column::mono('mobile', 'Mobile'), Column::text('category', 'Space type'),
            Column::mono('seats', 'Seats'), Column::date('end_date', 'Ends'), Column::int('days_left', 'Days left', false), Column::money('grand_total', 'Current value (₹)'),
            Column::text('state', 'Renewal'), Column::mono('renewal_no', 'Renewal booking'),
        ], $rows, 'Renewals', notes: ['Confirmed and active day-based bookings ending in the window, as of ' . format_date($f->today) . '.']);
    }

    public function fileStem(ReportFilters $filters): string
    {
        return 'renewals-' . $filters->today . '-next-' . $filters->get('within', '30') . '-days';
    }
}
