<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\ConfirmationRule;
use App\Services\Payments\DuesCalculator;
use App\Services\Payments\RentSchedule;
use PHPUnit\Framework\TestCase;

/** Rent schedule + dues maths (pure, no database). */
final class DuesCalculatorTest extends TestCase
{
    public function testRentScheduleSplitsWholeMonthsAndAddsUpExactly(): void
    {
        // 8 months: 1 Oct 2026 → 31 May 2027, ₹5,000 × 8 = 40,000 + 18 % GST
        $p = RentSchedule::build('2026-10-01', '2027-05-31', 40000.0, 7200.0, 30);
        self::assertCount(8, $p);
        self::assertSame('2026-10-01', $p[0]['period_start']);
        self::assertSame('2026-10-31', $p[0]['period_end']);
        self::assertSame('2027-05-01', $p[7]['due_on']);
        foreach ($p as $row) {
            self::assertSame(5900.0, $row['amount']);
        }
        self::assertEqualsWithDelta(47200.0, array_sum(array_column($p, 'amount')), 0.001);
    }

    public function testRentSchedulePartialLastPeriodAndRoundingRemainder(): void
    {
        // 7 months + 15 days → last period weighs 0.5 (15 / 30); odd totals keep the remainder in the last period
        $p = RentSchedule::build('2026-10-01', '2027-05-15', 37500.0, 6750.01, 30);
        self::assertCount(8, $p);
        self::assertSame('2027-05-15', $p[7]['period_end']);
        self::assertSame(0.5, $p[7]['weight']);
        self::assertEqualsWithDelta(44250.01, array_sum(array_column($p, 'amount')), 0.001);
        self::assertEqualsWithDelta(37500.0, array_sum(array_column($p, 'taxable')), 0.001);
    }

    public function testRentScheduleClampsMonthEnds(): void
    {
        $p = RentSchedule::build('2027-01-31', '2027-09-30', 8000.0, 0.0, 30);
        self::assertSame('2027-02-27', $p[0]['period_end']); // 31 Jan + 1 month clamps to 28 Feb, minus a day
        self::assertSame('2027-02-28', $p[1]['period_start']);
    }

    /** @return array<string, mixed> */
    private function booking(string $rule, float $grand, float $deposit = 0.0, string $status = 'approved'): array
    {
        return ['id' => 1, 'status' => $status, 'payment_rule' => $rule, 'grand_total' => $grand, 'deposit_amount' => $deposit, 'start_date' => '2026-10-01', 'payment_due_by' => '2026-10-04'];
    }

    /** @return array<string, mixed> */
    private function pay(string $kind, float $amount, string $status = 'logged'): array
    {
        return ['kind' => $kind, 'amount' => $amount, 'status' => $status];
    }

    public function testAdvanceNeedsTheFullAmount(): void
    {
        $b = $this->booking('advance', 9440.0);
        $d = DuesCalculator::calculate($b, [], [$this->pay('advance', 5000)], '2026-09-27');
        self::assertFalse($d['confirmation_met']);
        self::assertSame(4440.0, $d['balance']);
        self::assertSame(4440.0, $d['due_now']);
        $d = DuesCalculator::calculate($b, [], [$this->pay('advance', 5000), $this->pay('advance', 4440), $this->pay('advance', 999, 'void')], '2026-09-27');
        self::assertTrue($d['confirmation_met']);
        self::assertSame(0.0, $d['balance']);
        self::assertSame(9440.0, $d['paid'], 'void payments do not count');
    }

    public function testDepositRuleNeedsDepositPlusFirstPeriod(): void
    {
        $b = $this->booking('security_deposit', 47200.0, 10000.0);
        $schedule = [];
        foreach (RentSchedule::build('2026-10-01', '2027-05-31', 40000.0, 7200.0) as $p) {
            $schedule[] = $p + ['status' => 'open'];
        }
        $req = ConfirmationRule::requirement($b, $schedule);
        self::assertSame(15900.0, $req['total']);

        $d = DuesCalculator::calculate($b, $schedule, [$this->pay('deposit', 10000)], '2026-09-27');
        self::assertFalse($d['confirmation_met']);
        self::assertSame(5900.0, $d['due_now'], 'the first period is due before confirmation');

        $d = DuesCalculator::calculate($b, $schedule, [$this->pay('deposit', 10000), $this->pay('rent', 5900)], '2026-09-27');
        self::assertTrue($d['confirmation_met']);
        self::assertSame('paid', $d['schedule'][0]['state']);
        self::assertSame('upcoming', $d['schedule'][1]['state']);
        self::assertSame(0.0, $d['due_now']);
        self::assertSame(57200.0, $d['total']);
        self::assertSame(41300.0, $d['balance']);

        // a month later period 2 is due; two months later it is overdue
        $d = DuesCalculator::calculate($b + ['status' => 'active'], $schedule, [$this->pay('deposit', 10000), $this->pay('rent', 5900)], '2026-11-01');
        self::assertSame(5900.0, $d['due_now']);
        self::assertSame('due', $d['schedule'][1]['state']);
        $d = DuesCalculator::calculate($b, $schedule, [$this->pay('deposit', 10000), $this->pay('rent', 5900)], '2026-11-10');
        self::assertSame('overdue', $d['schedule'][1]['state']);
    }

    public function testSurplusSpillsBetweenDepositAndRent(): void
    {
        $b = $this->booking('security_deposit', 47200.0, 10000.0);
        $schedule = array_map(static fn (array $p) => $p + ['status' => 'open'], RentSchedule::build('2026-10-01', '2027-05-31', 40000.0, 7200.0));
        // one lump sum logged as a deposit covers the deposit and the first month
        $d = DuesCalculator::calculate($b, $schedule, [$this->pay('deposit', 15900)], '2026-09-27');
        self::assertTrue($d['confirmation_met']);
        self::assertSame(10000.0, $d['deposit']['paid']);
        self::assertSame(5900.0, $d['schedule'][0]['paid']);
    }

    public function testCancelledPeriodsAreIgnoredAndNothingIsDueForUnbillableBookings(): void
    {
        $b = $this->booking('security_deposit', 47200.0, 10000.0, 'requested');
        $schedule = array_map(static fn (array $p) => $p + ['status' => $p['period_no'] > 4 ? 'cancelled' : 'open'], RentSchedule::build('2026-10-01', '2027-05-31', 40000.0, 7200.0));
        $d = DuesCalculator::calculate($b, $schedule, [], '2026-09-27');
        self::assertCount(4, $d['schedule']);
        self::assertSame(33600.0, $d['total']);
        self::assertSame(0.0, $d['due_now']);
    }
}
