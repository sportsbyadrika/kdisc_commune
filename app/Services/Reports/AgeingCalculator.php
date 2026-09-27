<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\PaymentRule;
use DateTimeImmutable;

/**
 * Splits a booking's "due now" (PaymentLedger::dues / DuesCalculator) into ageing buckets by how many days each unpaid
 * piece is past its due date:
 *
 *   advance booking     the unpaid balance, due on payment_due_by (else the start date)
 *   security deposit    the unpaid deposit (same due date) + every due rent period's unpaid part (its due_on)
 *
 * Buckets: current (due date still ahead — e.g. the first rent period is always due), 0–30, 31–60, 61–90, 90+ days.
 * Pure — unit-tested.
 */
final class AgeingCalculator
{
    public const BUCKETS = ['current' => 'Not yet due', 'd0_30' => '0–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90' => '90+ days'];

    /**
     * @param array<string, mixed> $booking bookings row
     * @param array<string, mixed> $dues DuesCalculator::calculate()
     * @return array{current: float, d0_30: float, d31_60: float, d61_90: float, d90: float, total: float, oldest_days: int, oldest_due: ?string}
     */
    public static function buckets(array $booking, array $dues, string $today): array
    {
        $out = array_fill_keys(array_keys(self::BUCKETS), 0.0) + ['total' => 0.0, 'oldest_days' => 0, 'oldest_due' => null];
        if (empty($dues['billable']) || (float) ($dues['due_now'] ?? 0) <= 0) {
            return $out;
        }
        $pieces = [];
        $payBy = (string) ($booking['payment_due_by'] ?? '') !== '' ? (string) $booking['payment_due_by'] : (string) $booking['start_date'];
        $rule = $dues['rule'] instanceof PaymentRule ? $dues['rule'] : PaymentRule::tryFrom((string) $dues['rule']);
        if ($rule === PaymentRule::Advance) {
            $pieces[] = [$payBy, (float) $dues['due_now']];
        } else {
            $dep = round((float) $dues['deposit']['due'] - (float) $dues['deposit']['paid'], 2);
            if ($dep > 0) {
                $pieces[] = [$payBy, $dep];
            }
            foreach ($dues['schedule'] as $i => $p) {
                $isDue = $i === 0 || (string) $p['due_on'] <= $today;
                if ($isDue && (float) $p['left'] > 0) {
                    $pieces[] = [(string) $p['due_on'], (float) $p['left']];
                }
            }
        }
        $t = new DateTimeImmutable($today);
        foreach ($pieces as [$date, $amount]) {
            $days = (int) (new DateTimeImmutable($date))->diff($t)->format('%r%a');
            $bucket = match (true) {
                $days < 0 => 'current',
                $days <= 30 => 'd0_30',
                $days <= 60 => 'd31_60',
                $days <= 90 => 'd61_90',
                default => 'd90',
            };
            $out[$bucket] = round($out[$bucket] + $amount, 2);
            $out['total'] = round($out['total'] + $amount, 2);
            if ($out['oldest_due'] === null || $date < $out['oldest_due']) {
                $out['oldest_due'] = $date;
                $out['oldest_days'] = max(0, $days);
            }
        }
        return $out;
    }
}
