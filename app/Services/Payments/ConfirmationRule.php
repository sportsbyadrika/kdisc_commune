<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentRule;

/**
 * THE rule for "what must be paid before a booking is confirmed" (spec 7.2) — the only place it is defined.
 * The payment rule itself is chosen at quote time (QuoteCalculator: hourly or ≤ advance_max_months → advance,
 * longer → security deposit) and stored in bookings.payment_rule.
 *
 *   advance            (≤ 6 months, hourly)  the FULL booking amount — bookings.grand_total incl. GST
 *   security_deposit   (> 6 months)          the security deposit (bookings.deposit_amount, not taxable)
 *                                            + the FIRST rent period from the rent schedule (incl. GST)
 *
 * Payments of kind "deposit" fill the deposit first; all other kinds (advance / rent / add-on) fill rent
 * periods in order. Surplus in one bucket spills into the other (see DuesCalculator). Logged and verified
 * payments count; void / rejected / refunded ones do not. KYC must also be verified to confirm
 * (BookingWorkflow checks that separately).
 */
final class ConfirmationRule
{
    public const TOLERANCE = 0.005;

    /**
     * @param array<string, mixed> $booking bookings row (payment_rule, grand_total, deposit_amount)
     * @param list<array<string, mixed>> $schedule open rent periods in order (security deposit bookings)
     * @return array{rule: PaymentRule, deposit: float, rent: float, total: float, label: string, summary: string}
     */
    public static function requirement(array $booking, array $schedule = []): array
    {
        $rule = PaymentRule::tryFrom((string) ($booking['payment_rule'] ?? '')) ?? PaymentRule::Advance;
        if ($rule === PaymentRule::Advance) {
            $total = round((float) $booking['grand_total'], 2);
            return [
                'rule' => $rule,
                'deposit' => 0.0,
                'rent' => $total,
                'total' => $total,
                'label' => 'Full advance',
                'summary' => sprintf('Tenure up to %d months: the full amount of %s (incl. GST) is paid in advance before confirmation.', self::advanceMonths(), money($total, fmod($total, 1.0) ? 2 : 0)),
            ];
        }
        $deposit = round((float) $booking['deposit_amount'], 2);
        $first = $schedule !== [] ? round((float) $schedule[0]['amount'], 2) : round((float) $booking['grand_total'], 2);
        return [
            'rule' => $rule,
            'deposit' => $deposit,
            'rent' => $first,
            'total' => round($deposit + $first, 2),
            'label' => 'Security deposit + first month',
            'summary' => sprintf('Longer than %d months: the security deposit of %s plus the first month’s rent of %s (incl. GST) before confirmation; later months are due on the 1st day of each period.', self::advanceMonths(), money($deposit), money($first, fmod($first, 1.0) ? 2 : 0)),
        ];
    }

    /**
     * @param array{deposit: float, rent: float} $requirement
     */
    public static function isMet(array $requirement, float $depositPaid, float $rentPaid): bool
    {
        return $depositPaid + self::TOLERANCE >= $requirement['deposit'] && $rentPaid + self::TOLERANCE >= $requirement['rent'];
    }

    private static function advanceMonths(): int
    {
        try {
            return (int) setting('advance_max_months', 6);
        } catch (\Throwable) {
            return 6;
        }
    }
}
