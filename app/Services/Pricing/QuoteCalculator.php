<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BillingUnit;
use App\Enums\FacilityUnit;
use App\Enums\PaymentRule;
use App\Enums\SeatCategory;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SpaceRuleException;

/**
 * Pure pricing maths (no database) — QuoteService loads rates/facilities and calls this.
 *
 * Seat charge per unit (rates already resolved seat ?? zone ?? category on the start date):
 *   FLEXI      see QuotePolicy::$flexiRule (default: < 1 month → daily × days; ≥ 1 month → monthly × months
 *              + remaining days × daily), daily part capped at the monthly rate when flexiCapMonthly
 *   DEDICATED  monthly × (months + days / daysPerMonth)        (partial months pro-rated)
 *   CABIN      monthly × (months + days / daysPerMonth)        (per whole cabin)
 *   CONF       hourly × hours
 * Add-ons: month → price × qty × months-equivalent; day → × days; use → × qty; hour → × hours.
 * GST per line: intra-state CGST = SGST = rate / 2; inter-state IGST = rate. Rounded per line to paise.
 * Payment rule: hourly or ≤ advanceMaxMonths → Advance (pay the grand total); longer → Security deposit
 * (depositMonths × monthly rent of the seats, not taxable).
 */
final class QuoteCalculator
{
    /**
     * @param list<array{id: int, code: string, label?: ?string, capacity?: int, rates: array<string, array{amount: float, gst_rate: float}>}> $seats
     * @param list<array{id: int, code: string, name: string, unit: ?string, price: float|string, gst_rate: float|string, qty: int, emoji?: ?string, icon?: ?string}> $addons
     */
    public function calculate(SeatCategory $category, BookingPeriod $period, array $seats, array $addons, bool $interState, QuotePolicy $policy): Quote
    {
        if ($seats === []) {
            throw new SpaceRuleException('Choose at least one seat.');
        }
        $duration = Duration::of($period);
        if ($category->hourlyOnly() !== $period->isHourly()) {
            throw new SpaceRuleException($category->hourlyOnly() ? 'The conference room is priced by the hour — pick a time slot.' : 'This space is priced by the day or month.');
        }

        $seatOut = [];
        $mode = null;
        foreach ($seats as $seat) {
            $charge = $this->seatCharge($category, $duration, $seat, $policy);
            $mode ??= $charge['mode'];
            $seatOut[] = [
                'seat_id' => (int) $seat['id'],
                'code' => (string) $seat['code'],
                'label' => (string) ($seat['label'] ?? $seat['code']),
                'capacity' => (int) ($seat['capacity'] ?? 1),
                'unit' => $charge['unit'],
                'unit_price' => $charge['unit_price'],
                'gst_rate' => $charge['gst_rate'],
                'amount' => $charge['amount'],
                'components' => $charge['components'],
                'detail' => $charge['detail'],
                'monthly' => $charge['monthly'],
            ];
        }
        [$durationUnit, $durationQty] = match (true) {
            $category->hourlyOnly() => [BillingUnit::Hour, $duration->hours],
            $mode === 'day' => [BillingUnit::Day, (float) $duration->totalDays],
            default => [BillingUnit::Month, $duration->monthsEquivalent($policy->daysPerMonth)],
        };

        $addonOut = [];
        foreach ($addons as $a) {
            $qty = max(1, (int) $a['qty']);
            $unit = FacilityUnit::tryFrom((string) $a['unit']) ?? FacilityUnit::Use;
            $price = round((float) $a['price'], 2);
            [$multiplier, $detail] = match ($unit) {
                FacilityUnit::Month => $period->isHourly()
                    ? throw new SpaceRuleException(sprintf('%s is billed monthly and cannot be added to an hourly booking.', $a['name']))
                    : [$duration->monthsEquivalent($policy->daysPerMonth), $this->fmtQty($duration->monthsEquivalent($policy->daysPerMonth), 'month')],
                FacilityUnit::Day => $period->isHourly()
                    ? throw new SpaceRuleException(sprintf('%s is billed daily and cannot be added to an hourly booking.', $a['name']))
                    : [(float) $duration->totalDays, $this->fmtQty((float) $duration->totalDays, 'day')],
                FacilityUnit::Hour => !$period->isHourly()
                    ? throw new SpaceRuleException(sprintf('%s is only available with hourly conference bookings.', $a['name']))
                    : [$duration->hours, $this->fmtQty($duration->hours, 'hour')],
                FacilityUnit::Use => [1.0, 'one-time'],
            };
            $amount = round($price * $qty * $multiplier, 2);
            $addonOut[] = [
                'facility_id' => (int) $a['id'],
                'code' => (string) $a['code'],
                'name' => (string) $a['name'],
                'emoji' => $a['emoji'] ?? null,
                'icon' => $a['icon'] ?? null,
                'unit' => $unit->value,
                'qty' => $qty,
                'unit_price' => $price,
                'gst_rate' => (float) $a['gst_rate'],
                'amount' => $amount,
                'detail' => sprintf('%s%s %s', $qty > 1 ? $qty . ' × ' : '', money($price) . '/' . ($unit === FacilityUnit::Use ? 'use' : $unit->value), $unit === FacilityUnit::Use ? '' : '× ' . $detail),
            ];
        }

        // Display lines: identical seat charges grouped, then add-ons. GST per line.
        $lines = [];
        $groups = [];
        foreach ($seatOut as $s) {
            $key = $s['amount'] . '|' . $s['gst_rate'] . '|' . $s['detail'];
            $groups[$key][] = $s;
        }
        foreach ($groups as $group) {
            $first = $group[0];
            $n = count($group);
            $noun = match ($category) {
                SeatCategory::Cabin => 'Cabin',
                SeatCategory::Conference => 'Conference room',
                default => $category->shortLabel() . ' seat',
            };
            $lines[] = $this->taxLine([
                'kind' => 'seat',
                'label' => ($n > 1 ? $n . ' × ' : '') . $noun . ' · ' . implode(', ', array_column($group, 'code')),
                'detail' => $first['detail'],
                'qty' => $n,
                'unit_amount' => $first['amount'],
                'amount' => round($first['amount'] * $n, 2),
                'gst_rate' => $first['gst_rate'],
            ], $interState);
        }
        foreach ($addonOut as $a) {
            $lines[] = $this->taxLine([
                'kind' => 'addon',
                'label' => $a['name'],
                'detail' => $a['detail'],
                'qty' => $a['qty'],
                'unit_amount' => $a['unit_price'],
                'amount' => $a['amount'],
                'gst_rate' => $a['gst_rate'],
                'emoji' => $a['emoji'],
                'icon' => $a['icon'],
            ], $interState);
        }

        $sum = static fn (string $k, string $kind = '') => round(array_sum(array_map(
            static fn (array $l) => $kind === '' || $l['kind'] === $kind ? (float) $l[$k] : 0.0,
            $lines,
        )), 2);
        $seatsTotal = $sum('amount', 'seat');
        $addonsTotal = $sum('amount', 'addon');
        $taxable = round($seatsTotal + $addonsTotal, 2);
        $cgst = $sum('cgst');
        $sgst = $sum('sgst');
        $igst = $sum('igst');
        $gst = round($cgst + $sgst + $igst, 2);
        $grand = round($taxable + $gst, 2);

        $monthlyRent = round(array_sum(array_column($seatOut, 'monthly')), 2);
        $rule = !$period->isHourly() && $duration->exceedsMonths($policy->advanceMaxMonths) ? PaymentRule::SecurityDeposit : PaymentRule::Advance;
        $deposit = $rule === PaymentRule::SecurityDeposit ? round($monthlyRent * $policy->depositMonths, 2) : 0.0;

        return new Quote(
            category: $category,
            period: $period,
            duration: $duration,
            durationUnit: $durationUnit,
            durationQty: round($durationQty, 2),
            seats: $seatOut,
            addons: $addonOut,
            lines: $lines,
            interState: $interState,
            seatsTotal: $seatsTotal,
            addonsTotal: $addonsTotal,
            taxableTotal: $taxable,
            cgst: $cgst,
            sgst: $sgst,
            igst: $igst,
            gstTotal: $gst,
            grandTotal: $grand,
            paymentRule: $rule,
            depositAmount: $deposit,
            payableNow: $rule === PaymentRule::SecurityDeposit ? $deposit : $grand,
            monthlyRent: $monthlyRent,
        );
    }

    /**
     * @param array{id: int, code: string, rates: array<string, array{amount: float, gst_rate: float}>} $seat
     * @return array{mode: string, unit: string, unit_price: float, gst_rate: float, amount: float, components: list<array<string, mixed>>, detail: string, monthly: float}
     */
    private function seatCharge(SeatCategory $category, Duration $d, array $seat, QuotePolicy $p): array
    {
        $rates = $seat['rates'];
        $day = $rates['day'] ?? null;
        $month = $rates['month'] ?? null;
        $hour = $rates['hour'] ?? null;
        $missing = static fn (string $u) => new SpaceRuleException(sprintf('No %s rate is set for %s yet — please contact the front desk.', $u, $seat['code']));
        $comp = static fn (string $unit, float $qty, float $rate, ?string $note = null) => [
            'unit' => $unit, 'qty' => round($qty, 4), 'rate' => $rate, 'amount' => round($qty * $rate, 2), 'note' => $note,
        ];

        if ($category->hourlyOnly()) {
            $hour ?? throw $missing('hourly');
            $c = [$comp('hour', $d->hours, $hour['amount'])];
            return $this->charge('hour', 'hour', $hour, $c, 0.0);
        }

        if ($category === SeatCategory::Flexi) {
            $rule = $p->flexiRule;
            if ($day === null && $month === null) {
                throw $missing('day or month');
            }
            if ($day === null) {
                $rule = QuotePolicy::FLEXI_MONTHLY_PRORATA;
            } elseif ($month === null) {
                $rule = QuotePolicy::FLEXI_DAILY_ONLY;
            }
            $monthly = $month['amount'] ?? round($day['amount'] * $p->daysPerMonth, 2);
            if ($rule === QuotePolicy::FLEXI_DAILY_ONLY) {
                /** @var array{amount: float, gst_rate: float} $day */
                return $this->charge('day', 'day', $day, [$comp('day', $d->totalDays, $day['amount'])], $monthly);
            }
            if ($rule === QuotePolicy::FLEXI_MONTHLY_PRORATA) {
                /** @var array{amount: float, gst_rate: float} $month */
                return $this->monthly($d, $month, $p, $comp);
            }
            /** @var array{amount: float, gst_rate: float} $day */
            // monthly_plus_daily
            $c = [];
            if ($d->months > 0) {
                /** @var array{amount: float, gst_rate: float} $month */
                $c[] = $comp('month', $d->months, $month['amount']);
            }
            $restDays = $d->months > 0 ? $d->days : $d->totalDays;
            if ($restDays > 0) {
                $daily = $comp('day', $restDays, $day['amount']);
                if ($p->flexiCapMonthly && $month !== null && $daily['amount'] > $month['amount']) {
                    $daily = $comp('month', 1, $month['amount'], sprintf('%d days capped at the monthly rate', $restDays));
                }
                $c[] = $daily;
            }
            $mode = $d->months === 0 && $c[0]['unit'] === 'day' ? 'day' : 'month';
            $headline = $mode === 'day' ? $day : ($month ?? $day);
            return $this->charge($mode, $mode, $headline, $c, $monthly);
        }

        // Dedicated seat / cabin: monthly, partial months pro-rated.
        $month ?? throw $missing('monthly');
        return $this->monthly($d, $month, $p, $comp);
    }

    /**
     * @param array{amount: float, gst_rate: float} $month
     * @return array{mode: string, unit: string, unit_price: float, gst_rate: float, amount: float, components: list<array<string, mixed>>, detail: string, monthly: float}
     */
    private function monthly(Duration $d, array $month, QuotePolicy $p, \Closure $comp): array
    {
        $c = [];
        if ($d->months > 0) {
            $c[] = $comp('month', $d->months, $month['amount']);
        }
        if ($d->days > 0) {
            $c[] = $comp('month', $d->days / $p->daysPerMonth, $month['amount'], sprintf('%d day%s pro-rated (÷%d)', $d->days, $d->days === 1 ? '' : 's', $p->daysPerMonth));
        }
        return $this->charge('month', 'month', $month, $c, $month['amount']);
    }

    /**
     * @param array{amount: float, gst_rate: float} $headline
     * @param list<array<string, mixed>> $components
     * @return array{mode: string, unit: string, unit_price: float, gst_rate: float, amount: float, components: list<array<string, mixed>>, detail: string, monthly: float}
     */
    private function charge(string $mode, string $unit, array $headline, array $components, float $monthly): array
    {
        $amount = round(array_sum(array_column($components, 'amount')), 2);
        $detail = implode(' + ', array_map(function (array $c): string {
            if (($c['note'] ?? null) !== null) {
                return $c['note'] . ' (' . money($c['amount']) . ')';
            }
            return $this->fmtQty((float) $c['qty'], (string) $c['unit']) . ' × ' . money($c['rate']);
        }, $components));
        return [
            'mode' => $mode,
            'unit' => $unit,
            'unit_price' => round((float) $headline['amount'], 2),
            'gst_rate' => round((float) $headline['gst_rate'], 2),
            'amount' => $amount,
            'components' => $components,
            'detail' => $detail,
            'monthly' => $monthly,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function taxLine(array $line, bool $interState): array
    {
        $amount = (float) $line['amount'];
        $rate = (float) $line['gst_rate'];
        if ($interState) {
            $line['cgst'] = 0.0;
            $line['sgst'] = 0.0;
            $line['igst'] = round($amount * $rate / 100, 2);
        } else {
            $line['cgst'] = round($amount * $rate / 200, 2);
            $line['sgst'] = round($amount * $rate / 200, 2);
            $line['igst'] = 0.0;
        }
        $line['total'] = round($amount + $line['cgst'] + $line['sgst'] + $line['igst'], 2);
        return $line;
    }

    private function fmtQty(float $qty, string $unit): string
    {
        $n = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        return $n . ' ' . $unit . ($qty == 1.0 ? '' : 's');
    }
}
