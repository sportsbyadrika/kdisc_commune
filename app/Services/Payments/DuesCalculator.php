<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentRule;
use App\Enums\PaymentStatus;

/**
 * Dues of one booking — pure maths over the booking row, its rent schedule and its payments.
 *
 *   advance            total = grand total; everything is due once the booking is approved.
 *   security_deposit   total = deposit + open rent periods; due now = unpaid deposit + unpaid periods whose due
 *                      date has arrived (the first period is always due — it is part of the confirmation rule).
 *
 * Allocation: deposit-kind payments → deposit first, surplus → rent; other payments → rent periods in order
 * (FIFO), surplus → deposit. Only logged/verified payments count (void/rejected/refunded are ignored).
 * Nothing is "due" for bookings that are not billable (requested / rejected / cancelled).
 */
final class DuesCalculator
{
    /**
     * @param array<string, mixed> $booking
     * @param list<array<string, mixed>> $schedule rent_schedules rows (any status; cancelled ones are ignored)
     * @param list<array<string, mixed>> $payments payments rows
     * @return array<string, mixed>
     */
    public static function calculate(array $booking, array $schedule, array $payments, string $today): array
    {
        $rule = PaymentRule::tryFrom((string) ($booking['payment_rule'] ?? '')) ?? PaymentRule::Advance;
        $status = BookingStatus::tryFrom((string) ($booking['status'] ?? '')) ?? BookingStatus::Requested;
        $open = array_values(array_filter($schedule, static fn (array $p) => ($p['status'] ?? 'open') !== 'cancelled'));
        usort($open, static fn (array $a, array $b) => (int) $a['period_no'] <=> (int) $b['period_no']);

        $depositIn = 0.0;
        $otherIn = 0.0;
        foreach ($payments as $p) {
            if (!in_array((string) $p['status'], PaymentStatus::countingValues(), true)) {
                continue;
            }
            if ((string) $p['kind'] === PaymentKind::Deposit->value) {
                $depositIn += (float) $p['amount'];
            } else {
                $otherIn += (float) $p['amount'];
            }
        }
        $paid = round($depositIn + $otherIn, 2);
        $requirement = ConfirmationRule::requirement($booking, $open);
        $billable = $status->billable();

        if ($rule === PaymentRule::Advance) {
            $total = round((float) $booking['grand_total'], 2);
            $balance = round(max(0.0, $total - $paid), 2);
            return [
                'rule' => $rule,
                'billable' => $billable,
                'requirement' => $requirement,
                'confirmation_met' => ConfirmationRule::isMet($requirement, 0.0, $paid),
                'deposit' => ['due' => 0.0, 'paid' => 0.0],
                'rent' => ['due' => $total, 'paid' => min($total, $paid)],
                'total' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'due_now' => $billable ? $balance : 0.0,
                'overpaid' => round(max(0.0, $paid - $total), 2),
                'schedule' => [],
                'next_due' => $billable && $balance > 0 ? ['date' => $booking['payment_due_by'] ?? $booking['start_date'], 'amount' => $balance, 'label' => 'Advance'] : null,
            ];
        }

        $deposit = round((float) $booking['deposit_amount'], 2);
        $rentTotal = round(array_sum(array_map(static fn (array $p) => (float) $p['amount'], $open)), 2);
        if ($open === []) {
            $rentTotal = round((float) $booking['grand_total'], 2);
        }
        // deposit bucket
        $depositPaid = min($deposit, $depositIn);
        $rentPool = $otherIn + max(0.0, $depositIn - $deposit);
        // surplus rent → deposit
        if ($depositPaid < $deposit && $rentPool > $rentTotal) {
            $take = min($deposit - $depositPaid, $rentPool - $rentTotal);
            $depositPaid += $take;
            $rentPool -= $take;
        }
        $rentPaid = round(min($rentPool, $rentTotal), 2);

        $pool = $rentPool;
        $rows = [];
        $dueNow = round($deposit - $depositPaid, 2);
        $nextDue = $deposit - $depositPaid > ConfirmationRule::TOLERANCE ? ['date' => $booking['payment_due_by'] ?? $booking['start_date'], 'amount' => round($deposit - $depositPaid, 2), 'label' => 'Security deposit'] : null;
        foreach ($open as $i => $p) {
            $amount = round((float) $p['amount'], 2);
            $got = round(min($amount, max(0.0, $pool)), 2);
            $pool -= $got;
            $left = round($amount - $got, 2);
            $isDue = $i === 0 || (string) $p['due_on'] <= $today;
            $state = match (true) {
                $left <= ConfirmationRule::TOLERANCE => 'paid',
                $got > 0 => 'partial',
                $isDue && (string) $p['due_on'] < $today => 'overdue',
                $isDue => 'due',
                default => 'upcoming',
            };
            if ($isDue) {
                $dueNow += $left;
            }
            if ($nextDue === null && $left > ConfirmationRule::TOLERANCE) {
                $nextDue = ['date' => (string) $p['due_on'], 'amount' => $left, 'label' => 'Rent · period ' . $p['period_no']];
            }
            $rows[] = $p + ['paid' => $got, 'left' => $left, 'state' => $state];
        }
        $total = round($deposit + $rentTotal, 2);
        $balance = round(max(0.0, $total - $depositPaid - $rentPaid), 2);
        return [
            'rule' => $rule,
            'billable' => $billable,
            'requirement' => $requirement,
            'confirmation_met' => ConfirmationRule::isMet($requirement, $depositPaid, $open !== [] ? (float) ($rows[0]['paid'] ?? 0) : $rentPaid),
            'deposit' => ['due' => $deposit, 'paid' => round($depositPaid, 2)],
            'rent' => ['due' => $rentTotal, 'paid' => $rentPaid],
            'total' => $total,
            'paid' => $paid,
            'balance' => $balance,
            'due_now' => $billable ? round(max(0.0, $dueNow), 2) : 0.0,
            'overpaid' => round(max(0.0, $paid - $total), 2),
            'schedule' => $rows,
            'next_due' => $billable ? $nextDue : null,
        ];
    }
}
