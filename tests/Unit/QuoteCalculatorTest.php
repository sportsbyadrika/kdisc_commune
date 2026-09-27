<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BillingUnit;
use App\Enums\PaymentRule;
use App\Enums\SeatCategory;
use App\Services\Pricing\Duration;
use App\Services\Pricing\QuoteCalculator;
use App\Services\Pricing\QuotePolicy;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SpaceRuleException;
use PHPUnit\Framework\TestCase;

/** Pure pricing maths (spec 5.5 / 7.2 / 7.3) — no database. */
final class QuoteCalculatorTest extends TestCase
{
    private QuoteCalculator $calc;

    private QuotePolicy $policy;

    protected function setUp(): void
    {
        $this->calc = new QuoteCalculator();
        $this->policy = new QuotePolicy();
    }

    /** @return list<array{id: int, code: string, capacity: int, rates: array<string, array{amount: float, gst_rate: float}>}> */
    private function seats(SeatCategory $cat, int $n = 1): array
    {
        $rates = match ($cat) {
            SeatCategory::Flexi => ['day' => 500.0, 'month' => 4000.0],
            SeatCategory::Dedicated => ['month' => 5000.0],
            SeatCategory::Cabin => ['month' => 8000.0],
            SeatCategory::Conference => ['hour' => 500.0],
        };
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'id' => $i, 'code' => sprintf('G-%s-%02d', $cat->codeSegment(), $i), 'capacity' => $cat->wholeUnitOnly() ? ($cat === SeatCategory::Cabin ? 3 : 9) : 1,
                'rates' => array_map(static fn (float $a) => ['amount' => $a, 'gst_rate' => 18.0], $rates),
            ];
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $addons */
    private function quote(SeatCategory $cat, BookingPeriod $p, int $n = 1, array $addons = [], bool $inter = false, ?QuotePolicy $policy = null): \App\Services\Pricing\Quote
    {
        /** @phpstan-ignore argument.type */
        return $this->calc->calculate($cat, $p, $this->seats($cat, $n), $addons, $inter, $policy ?? $this->policy);
    }

    // ---------------------------------------------------------------- durations

    public function testDurationSplitsWholeCalendarMonthsAndDays(): void
    {
        $d = Duration::of(BookingPeriod::days('2026-10-01', '2026-10-31'));
        self::assertSame([1, 0, 31], [$d->months, $d->days, $d->totalDays]);
        $d = Duration::of(BookingPeriod::days('2026-10-01', '2026-11-15'));
        self::assertSame([1, 15], [$d->months, $d->days]);
        self::assertSame('1 month 15 days', $d->label());
        $d = Duration::of(BookingPeriod::days('2026-10-01', '2026-10-20'));
        self::assertSame([0, 20, 20], [$d->months, $d->days, $d->totalDays]);
        $d = Duration::of(BookingPeriod::days('2027-01-31', '2027-02-27'));
        self::assertSame([1, 0], [$d->months, $d->days], '31 Jan + 1 month clamps to 28 Feb');
        self::assertSame(3.0, Duration::of(BookingPeriod::hours('2026-10-01', '10:00', '13:00'))->hours);
    }

    public function testSixMonthBoundary(): void
    {
        $exact = Duration::of(BookingPeriod::days('2026-10-01', '2027-03-31'));
        self::assertSame([6, 0], [$exact->months, $exact->days]);
        self::assertFalse($exact->exceedsMonths(6));
        self::assertTrue(Duration::of(BookingPeriod::days('2026-10-01', '2027-04-01'))->exceedsMonths(6));
    }

    // ---------------------------------------------------------------- flexi

    public function testFlexiUnderAMonthIsDailyRateTimesDays(): void
    {
        $q = $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-10-05'), 2);
        self::assertSame(BillingUnit::Day, $q->durationUnit);
        self::assertSame(5.0, $q->durationQty);
        self::assertSame(5000.0, $q->seatsTotal); // 2 seats x 5 days x 500
        self::assertSame(2500.0, $q->seats[0]['amount']);
        self::assertSame(500.0, $q->seats[0]['unit_price']);
    }

    public function testFlexiDailyPartIsCappedAtTheMonthlyRate(): void
    {
        $q = $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-10-20')); // 20 x 500 = 10,000 > 4,000
        self::assertSame(4000.0, $q->seatsTotal);
        self::assertStringContainsString('capped', (string) $q->seats[0]['detail']);

        $uncapped = new QuotePolicy(flexiCapMonthly: false);
        self::assertSame(10000.0, $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-10-20'), 1, [], false, $uncapped)->seatsTotal);
    }

    public function testFlexiMonthPlusRemainingDays(): void
    {
        $q = $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-11-05')); // 1 month + 5 days
        self::assertSame(4000.0 + 5 * 500.0, $q->seatsTotal);
        self::assertSame(BillingUnit::Month, $q->durationUnit);
        self::assertCount(2, $q->seats[0]['components']);

        $one = $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-10-31'));
        self::assertSame(4000.0, $one->seatsTotal);
    }

    public function testFlexiAlternativeRules(): void
    {
        $prorata = new QuotePolicy(flexiRule: QuotePolicy::FLEXI_MONTHLY_PRORATA);
        self::assertSame(6000.0, $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-11-15'), 1, [], false, $prorata)->seatsTotal);
        $daily = new QuotePolicy(flexiRule: QuotePolicy::FLEXI_DAILY_ONLY);
        self::assertSame(46 * 500.0, $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-11-15'), 1, [], false, $daily)->seatsTotal);
    }

    // ---------------------------------------------------------------- dedicated / cabin / conference

    public function testDedicatedProRatesPartialMonths(): void
    {
        $q = $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2026-11-15'), 4);
        self::assertSame(7500.0, $q->seats[0]['amount']); // 5000 x (1 + 15/30)
        self::assertSame(30000.0, $q->seatsTotal);
        self::assertSame(1.5, $q->durationQty);
        self::assertCount(1, $q->lines, 'identical seats are grouped into one display line');
        self::assertStringStartsWith('4 × Dedicated seat', (string) $q->lines[0]['label']);

        $short = $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2026-10-10'));
        self::assertEqualsWithDelta(5000 * 10 / 30, $short->seatsTotal, 0.01);
    }

    public function testCabinIsPricedPerWholeCabin(): void
    {
        $q = $this->quote(SeatCategory::Cabin, BookingPeriod::days('2026-10-01', '2026-12-31')); // 3 months
        self::assertSame(24000.0, $q->seatsTotal);
        self::assertSame(3, $q->seatCount(), 'a cabin seats three');
    }

    public function testConferenceIsHourly(): void
    {
        $q = $this->quote(SeatCategory::Conference, BookingPeriod::hours('2026-10-01', '10:00', '13:00'));
        self::assertSame(1500.0, $q->seatsTotal);
        self::assertSame(BillingUnit::Hour, $q->durationUnit);
        self::assertSame(PaymentRule::Advance, $q->paymentRule);
        $this->expectException(SpaceRuleException::class);
        $this->quote(SeatCategory::Conference, BookingPeriod::days('2026-10-01', '2026-10-01'));
    }

    public function testMissingRateIsReported(): void
    {
        $this->expectException(SpaceRuleException::class);
        $this->calc->calculate(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2026-10-31'), [['id' => 1, 'code' => 'X', 'rates' => []]], [], false, $this->policy);
    }

    // ---------------------------------------------------------------- GST

    public function testIntraStateSplitsCgstAndSgst(): void
    {
        $q = $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2026-10-31'));
        self::assertSame(450.0, $q->cgst);
        self::assertSame(450.0, $q->sgst);
        self::assertSame(0.0, $q->igst);
        self::assertSame(5900.0, $q->grandTotal);
        self::assertSame('intra', $q->toArray()['tax_mode']);
    }

    public function testInterStateUsesIgst(): void
    {
        $q = $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2026-10-31'), 1, [], true);
        self::assertSame(0.0, $q->cgst + $q->sgst);
        self::assertSame(900.0, $q->igst);
        self::assertSame(5900.0, $q->grandTotal);
    }

    public function testPolicyDecidesInterStateFromStateCode(): void
    {
        self::assertFalse($this->policy->isInterState('32'));
        self::assertFalse($this->policy->isInterState(null), 'unknown state = intra-state');
        self::assertFalse($this->policy->isInterState(''));
        self::assertTrue($this->policy->isInterState('29'));
    }

    // ---------------------------------------------------------------- add-ons

    public function testAddOnsPerUnit(): void
    {
        $addons = [
            ['id' => 6, 'code' => 'LOCKER', 'name' => 'Locker', 'unit' => 'month', 'price' => 300, 'gst_rate' => 18, 'qty' => 2],
            ['id' => 8, 'code' => 'PRINTING', 'name' => 'Printing pack', 'unit' => 'use', 'price' => 200, 'gst_rate' => 18, 'qty' => 1],
            ['id' => 9, 'code' => 'DAYPASS', 'name' => 'Day pass', 'unit' => 'day', 'price' => 50, 'gst_rate' => 12, 'qty' => 1],
        ];
        $q = $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-11-15'), 2, $addons);
        self::assertSame(900.0, $q->addons[0]['amount']); // 300 x 2 x 1.5 months
        self::assertSame(200.0, $q->addons[1]['amount']);
        self::assertSame(2300.0, $q->addons[2]['amount']); // 50 x 46 days
        self::assertSame(3400.0, $q->addonsTotal);
        // GST per line: the day pass is taxed at its own 12 %
        $dayLine = $q->lines[array_key_last($q->lines)];
        self::assertSame(138.0, $dayLine['cgst']);
        self::assertSame(round($q->taxableTotal + $q->gstTotal, 2), $q->grandTotal);
    }

    public function testHourlyAddOnOnlyWithHourlyBookings(): void
    {
        $projector = [['id' => 9, 'code' => 'PROJECTOR', 'name' => 'Projector', 'unit' => 'hour', 'price' => 200, 'gst_rate' => 18, 'qty' => 1]];
        $q = $this->quote(SeatCategory::Conference, BookingPeriod::hours('2026-10-01', '09:00', '11:00'), 1, $projector);
        self::assertSame(400.0, $q->addonsTotal);
        $this->expectException(SpaceRuleException::class);
        $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2026-10-31'), 1, $projector);
    }

    // ---------------------------------------------------------------- payment rule

    public function testAdvanceUpToSixMonths(): void
    {
        $q = $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2027-03-31'), 2);
        self::assertSame(PaymentRule::Advance, $q->paymentRule);
        self::assertSame(0.0, $q->depositAmount);
        self::assertSame($q->grandTotal, $q->payableNow);
    }

    public function testSecurityDepositBeyondSixMonths(): void
    {
        $q = $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2027-04-01'), 2);
        self::assertSame(PaymentRule::SecurityDeposit, $q->paymentRule);
        self::assertSame(10000.0, $q->monthlyRent);
        self::assertSame(20000.0, $q->depositAmount, 'default 2 months of rent');
        self::assertSame(20000.0, $q->payableNow);
        $three = new QuotePolicy(depositMonths: 3);
        self::assertSame(30000.0, $this->quote(SeatCategory::Dedicated, BookingPeriod::days('2026-10-01', '2027-09-30'), 2, [], false, $three)->depositAmount);
    }

    public function testQuoteSerialises(): void
    {
        $a = $this->quote(SeatCategory::Flexi, BookingPeriod::days('2026-10-01', '2026-10-03'))->toArray();
        self::assertSame('FLEXI', $a['category']['code']);
        self::assertSame(1770.0, $a['totals']['grand']);
        self::assertSame('advance', $a['payment']['rule']);
        self::assertSame(1, $a['seat_count']);
    }

    public function testBookingPeriodValidation(): void
    {
        $p = BookingPeriod::fromInput(['from' => '2026-10-01', 'start_time' => '9:00', 'end_time' => '12:00']);
        self::assertTrue($p->isHourly());
        self::assertSame('2026-10-01 09:00:00', $p->startAt());
        self::assertSame('2026-10-01 12:00:00', $p->endAt());
        self::assertSame('2026-11-01 00:00:00', BookingPeriod::days('2026-10-01', '2026-10-31')->endAt());
        $this->expectException(\InvalidArgumentException::class);
        BookingPeriod::days('2026-10-10', '2026-10-01');
    }
}
