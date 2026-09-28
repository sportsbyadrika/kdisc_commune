<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Services\Payments\PaymentLedger;
use App\Services\Reports\AgeingCalculator;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Money due now, aged 0–30 / 31–60 / 61–90 / 90+ days past its due date — per booking or per visitor. */
final class DuesAgeingReport extends Report
{
    public function __construct(private readonly Database $db, private readonly PaymentLedger $ledger)
    {
    }

    public function key(): string
    {
        return 'dues-ageing';
    }

    public function title(): string
    {
        return 'Dues ageing';
    }

    public function description(): string
    {
        return 'Outstanding dues bucketed by days past due (0–30, 31–60, 61–90, 90+), per booking or per visitor.';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function icon(): string
    {
        return 'hourglass';
    }

    public function ability(): string
    {
        return 'dues.view';
    }

    public function filters(): array
    {
        return [Filter::select('group', 'Show', ['booking' => 'Per booking', 'visitor' => 'Per visitor'], 'booking')];
    }

    /** @return list<array<string, mixed>> one row per booking with money due now */
    public function rows(string $today): array
    {
        $st = BookingStatus::billableValues();
        $in = implode(',', array_fill(0, count($st), '?'));
        $out = [];
        $bookings = $this->db->select(
            "SELECT b.*, c.name AS customer_name, c.unique_id, c.mobile FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.status IN ({$in}) ORDER BY b.id",
            $st,
        );
        $all = $this->ledger->duesMany($bookings);
        foreach ($bookings as $b) {
            $a = AgeingCalculator::buckets($b, $all[(int) $b['id']], $today);
            if ($a['total'] <= 0) {
                continue;
            }
            $out[] = $a + [
                'booking_no' => (string) $b['booking_no'], 'customer_id' => (int) $b['customer_id'], 'customer_name' => (string) $b['customer_name'],
                'unique_id' => (string) ($b['unique_id'] ?? ''), 'mobile' => (string) $b['mobile'], 'status_label' => BookingStatus::from((string) $b['status'])->label(),
            ];
        }
        usort($out, static fn (array $x, array $y) => [$y['oldest_days'], $y['total']] <=> [$x['oldest_days'], $x['total']]);
        return $out;
    }

    public function run(ReportFilters $f): ReportResult
    {
        $rows = $this->rows($f->today);
        $buckets = [];
        foreach (AgeingCalculator::BUCKETS as $k => $label) {
            $buckets[] = Column::money($k, $label . ' (₹)');
        }
        $chart = ['kind' => 'bar-money', 'label' => 'Due now', 'labels' => array_values(AgeingCalculator::BUCKETS),
            'values' => array_map(static fn (string $k) => round(array_sum(array_column($rows, $k)), 2), array_keys(AgeingCalculator::BUCKETS))];
        $notes = ['As of ' . format_date($f->today) . '. Only money already due (advance balance, unpaid deposit, rent periods on/after their due date).'];
        if ($f->get('group') === 'visitor') {
            $by = [];
            foreach ($rows as $r) {
                $id = $r['customer_id'];
                $by[$id] ??= ['customer_name' => $r['customer_name'], 'unique_id' => $r['unique_id'], 'mobile' => $r['mobile'], 'bookings' => 0, 'oldest_days' => 0]
                    + array_fill_keys(array_keys(AgeingCalculator::BUCKETS), 0.0) + ['total' => 0.0];
                $by[$id]['bookings']++;
                $by[$id]['oldest_days'] = max($by[$id]['oldest_days'], $r['oldest_days']);
                foreach ([...array_keys(AgeingCalculator::BUCKETS), 'total'] as $k) {
                    $by[$id][$k] = round($by[$id][$k] + $r[$k], 2);
                }
            }
            $vrows = array_values($by);
            usort($vrows, static fn (array $x, array $y) => $y['total'] <=> $x['total']);
            return new ReportResult('Dues ageing by visitor', [
                Column::text('customer_name', 'Visitor'), Column::mono('unique_id', 'Unique ID'), Column::mono('mobile', 'Mobile'), Column::int('bookings', 'Bookings'),
                ...$buckets, Column::money('total', 'Total due (₹)'), Column::int('oldest_days', 'Oldest (days)', false),
            ], $vrows, 'Dues ageing', notes: $notes, chart: $chart);
        }
        return new ReportResult('Dues ageing by booking', [
            Column::mono('booking_no', 'Booking'), Column::text('customer_name', 'Visitor'), Column::mono('unique_id', 'Unique ID'), Column::text('status_label', 'Status', 12),
            Column::date('oldest_due', 'Oldest due'), Column::int('oldest_days', 'Days past', false), ...$buckets, Column::money('total', 'Total due (₹)'),
        ], $rows, 'Dues ageing', notes: $notes, chart: $chart);
    }

    public function fileStem(ReportFilters $filters): string
    {
        return 'dues-ageing-' . $filters->today;
    }
}
