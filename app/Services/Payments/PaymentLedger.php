<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Enums\PaymentRule;
use App\Enums\PaymentStatus;
use App\Services\SettingsService;
use App\Support\Clock;

/**
 * Read side of money per booking / customer: rent schedules, payments, dues (DuesCalculator) and outstanding
 * totals. Used by PaymentService (writes), BookingWorkflow (confirmation check) and the pages.
 *
 * Rent schedules are generated lazily for security-deposit bookings (first time they are read) from the
 * booking's taxable total + GST — RentSchedule::build() — and are never regenerated; an early exit marks the
 * periods after the exit date `cancelled`.
 */
final class PaymentLedger
{
    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly SettingsService $settings)
    {
    }

    /**
     * Rent periods of a booking (generated on first use for security-deposit bookings).
     *
     * @param array<string, mixed> $booking
     * @return list<array<string, mixed>>
     */
    public function schedule(array $booking): array
    {
        if (($booking['payment_rule'] ?? '') !== PaymentRule::SecurityDeposit->value) {
            return [];
        }
        $rows = $this->db->select('SELECT * FROM rent_schedules WHERE booking_id = ? ORDER BY period_no', [(int) $booking['id']]);
        if ($rows !== []) {
            return $rows;
        }
        $this->generate($booking);
        return $this->db->select('SELECT * FROM rent_schedules WHERE booking_id = ? ORDER BY period_no', [(int) $booking['id']]);
    }

    /** @param array<string, mixed> $booking */
    public function generate(array $booking): void
    {
        $periods = RentSchedule::build(
            (string) $booking['start_date'],
            (string) (($booking['original_end_date'] ?? '') ?: $booking['end_date']),
            round((float) $booking['subtotal'] + (float) $booking['facilities_total'], 2),
            (float) $booking['gst_total'],
            max(1, (int) $this->settings->get('proration_days_per_month', 30)),
        );
        foreach ($periods as $p) {
            $this->db->execute(
                'INSERT IGNORE INTO rent_schedules (booking_id, period_no, period_start, period_end, due_on, taxable, gst, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $booking['id'], $p['period_no'], $p['period_start'], $p['period_end'], $p['due_on'], $p['taxable'], $p['gst'], $p['amount'], 'open'],
            );
        }
    }

    /** Early exit: periods that start after the new end date no longer fall due. */
    public function cancelPeriodsAfter(int $bookingId, string $lastDay): int
    {
        return $this->db->execute("UPDATE rent_schedules SET status = 'cancelled' WHERE booking_id = ? AND period_start > ? AND status = 'open'", [$bookingId, $lastDay]);
    }

    /**
     * Payments of a booking, newest first, with staff names.
     *
     * @return list<array<string, mixed>>
     */
    public function payments(int $bookingId): array
    {
        return $this->db->select(
            'SELECT p.*, l.name AS logged_by_name, v.name AS voided_by_name, f.name AS verified_by_name
             FROM payments p
             LEFT JOIN staff_users l ON l.id = p.logged_by
             LEFT JOIN staff_users v ON v.id = p.voided_by
             LEFT JOIN staff_users f ON f.id = p.verified_by
             WHERE p.booking_id = ? ORDER BY p.paid_on DESC, p.id DESC',
            [$bookingId],
        );
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed> DuesCalculator::calculate()
     */
    public function dues(array $booking): array
    {
        return DuesCalculator::calculate($booking, $this->schedule($booking), $this->payments((int) $booking['id']), $this->clock->today());
    }

    /**
     * Outstanding (due now) and remaining balance of one customer across billable bookings.
     *
     * @return array{due_now: float, balance: float, bookings: list<array{booking_no: string, due_now: float, balance: float}>}
     */
    public function customerOutstanding(int $customerId): array
    {
        $out = ['due_now' => 0.0, 'balance' => 0.0, 'bookings' => []];
        foreach ($this->billableBookings('b.customer_id = ?', [$customerId]) as $b) {
            $d = $this->dues($b);
            if ($d['balance'] <= 0 && $d['due_now'] <= 0) {
                continue;
            }
            $out['due_now'] += $d['due_now'];
            $out['balance'] += $d['balance'];
            $out['bookings'][] = ['booking_no' => (string) $b['booking_no'], 'due_now' => (float) $d['due_now'], 'balance' => (float) $d['balance']];
        }
        $out['due_now'] = round($out['due_now'], 2);
        $out['balance'] = round($out['balance'], 2);
        return $out;
    }

    /**
     * Customers with the most money due now (front-desk dashboard, dues list).
     *
     * @return list<array{customer_id: int, name: string, unique_id: ?string, due_now: float, balance: float, bookings: int}>
     */
    public function topOutstanding(int $limit = 5): array
    {
        $by = [];
        foreach ($this->billableBookings('1 = 1', []) as $b) {
            $d = $this->dues($b);
            if ($d['due_now'] <= 0) {
                continue;
            }
            $id = (int) $b['customer_id'];
            $by[$id] ??= ['customer_id' => $id, 'name' => (string) $b['customer_name'], 'unique_id' => $b['unique_id'], 'due_now' => 0.0, 'balance' => 0.0, 'bookings' => 0];
            $by[$id]['due_now'] = round($by[$id]['due_now'] + $d['due_now'], 2);
            $by[$id]['balance'] = round($by[$id]['balance'] + $d['balance'], 2);
            $by[$id]['bookings']++;
        }
        usort($by, static fn (array $a, array $b) => $b['due_now'] <=> $a['due_now']);
        return array_slice($by, 0, $limit);
    }

    /**
     * @param list<mixed> $bind
     * @return list<array<string, mixed>>
     */
    private function billableBookings(string $where, array $bind): array
    {
        $st = BookingStatus::billableValues();
        $in = implode(',', array_fill(0, count($st), '?'));
        return $this->db->select(
            "SELECT b.*, c.name AS customer_name, c.unique_id FROM bookings b JOIN customers c ON c.id = b.customer_id
             WHERE b.status IN ({$in}) AND {$where} ORDER BY b.id",
            [...$st, ...$bind],
        );
    }

    /** Sum of counting payments (logged + verified) — quick totals. */
    public function paidTotal(int $bookingId): float
    {
        $st = PaymentStatus::countingValues();
        return (float) $this->db->scalar('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND status IN (?, ?)', [$bookingId, ...$st]);
    }
}
