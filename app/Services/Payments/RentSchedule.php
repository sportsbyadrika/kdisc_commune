<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\Pricing\Duration;
use DateTimeImmutable;

/**
 * Monthly rent periods for a > 6-month (security deposit) booking — pure maths, no database.
 *
 * Periods follow calendar months from the start date (1 Oct → 31 Oct, 1 Nov → 30 Nov, …; a start on the 31st
 * clamps like Duration::addMonths). The last period is cut at the end date. Each period's weight is 1 for a
 * whole month, otherwise days ÷ daysPerMonth (same pro-rating as QuoteCalculator). The booking's taxable
 * amount and GST are split by weight, rounded to paise, with the rounding remainder in the last period — so the
 * schedule always adds up to the booking's grand total exactly. Rent is due on the first day of each period.
 */
final class RentSchedule
{
    /**
     * @return list<array{period_no: int, period_start: string, period_end: string, due_on: string, weight: float, taxable: float, gst: float, amount: float}>
     */
    public static function build(string $start, string $end, float $taxable, float $gst, int $daysPerMonth = 30): array
    {
        $from = new DateTimeImmutable($start);
        $to = new DateTimeImmutable($end);
        if ($to < $from) {
            return [];
        }
        $periods = [];
        for ($i = 0; ; $i++) {
            $pStart = Duration::addMonths($from, $i);
            if ($pStart > $to) {
                break;
            }
            $fullEnd = Duration::addMonths($from, $i + 1)->modify('-1 day');
            $pEnd = $fullEnd <= $to ? $fullEnd : $to;
            $weight = $pEnd == $fullEnd ? 1.0 : ((int) $pStart->diff($pEnd)->days + 1) / max(1, $daysPerMonth);
            $periods[] = ['period_no' => $i + 1, 'period_start' => $pStart->format('Y-m-d'), 'period_end' => $pEnd->format('Y-m-d'), 'due_on' => $pStart->format('Y-m-d'), 'weight' => round($weight, 6)];
            if ($i > 600) {
                break; // 50 years — defensive
            }
        }
        $total = array_sum(array_column($periods, 'weight'));
        $taxLeft = round($taxable, 2);
        $gstLeft = round($gst, 2);
        $last = count($periods) - 1;
        foreach ($periods as $i => &$p) {
            if ($i === $last) {
                $t = round($taxLeft, 2);
                $g = round($gstLeft, 2);
            } else {
                $t = round($taxable * $p['weight'] / $total, 2);
                $g = round($gst * $p['weight'] / $total, 2);
                $taxLeft -= $t;
                $gstLeft -= $g;
            }
            $p['taxable'] = $t;
            $p['gst'] = $g;
            $p['amount'] = round($t + $g, 2);
        }
        unset($p);
        return $periods;
    }
}
