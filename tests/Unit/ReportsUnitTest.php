<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PaymentRule;
use App\Services\AuditTrail;
use App\Services\Imports\ImportColumn;
use App\Services\Imports\SpreadsheetReader;
use App\Services\Reports\AgeingCalculator;
use App\Services\Reports\Column;
use App\Services\Reports\ReportResult;
use PHPUnit\Framework\TestCase;

/** Pure pieces of batch 7: ageing buckets, report totals / sorting, cell parsing for imports, audit diffs. */
final class ReportsUnitTest extends TestCase
{
    public function testAdvanceBalanceAgesFromItsPayByDate(): void
    {
        $booking = ['payment_due_by' => '2026-08-01', 'start_date' => '2026-08-05'];
        $dues = ['billable' => true, 'due_now' => 5000.0, 'rule' => PaymentRule::Advance, 'deposit' => ['due' => 0, 'paid' => 0], 'schedule' => []];
        $a = AgeingCalculator::buckets($booking, $dues, '2026-09-27');
        self::assertSame(5000.0, $a['d31_60']); // 57 days
        self::assertSame(5000.0, $a['total']);
        self::assertSame(57, $a['oldest_days']);
        self::assertSame('2026-08-01', $a['oldest_due']);
    }

    public function testDepositBookingSplitsDepositAndEachUnpaidPeriod(): void
    {
        $booking = ['payment_due_by' => null, 'start_date' => '2026-06-01'];
        $dues = ['billable' => true, 'due_now' => 1.0, 'rule' => 'security_deposit', 'deposit' => ['due' => 20000, 'paid' => 10000], 'schedule' => [
            ['due_on' => '2026-06-01', 'left' => 0.0],       // paid
            ['due_on' => '2026-07-01', 'left' => 11800.0],   // 88 days → 61–90
            ['due_on' => '2026-08-01', 'left' => 11800.0],   // 57 days → 31–60
            ['due_on' => '2026-09-01', 'left' => 5000.0],    // 26 days → 0–30
            ['due_on' => '2026-10-01', 'left' => 11800.0],   // not due yet → ignored
        ]];
        $a = AgeingCalculator::buckets($booking, $dues, '2026-09-27');
        self::assertSame(10000.0, $a['d90'], 'unpaid half of the deposit, due 1 Jun = 118 days');
        self::assertSame(11800.0, $a['d61_90']);
        self::assertSame(11800.0, $a['d31_60']);
        self::assertSame(5000.0, $a['d0_30']);
        self::assertSame(0.0, $a['current']);
        self::assertSame(38600.0, $a['total']);
    }

    public function testNothingIsAgedForNonBillableBookings(): void
    {
        $a = AgeingCalculator::buckets(['start_date' => '2026-01-01'], ['billable' => false, 'due_now' => 900.0, 'rule' => PaymentRule::Advance], '2026-09-27');
        self::assertSame(0.0, $a['total']);
    }

    public function testReportResultTotalsAndSorting(): void
    {
        $rows = [['n' => 'b', 'amt' => 10.5, 'c' => 2, 'p' => 0.5], ['n' => 'a', 'amt' => 2.25, 'c' => 1, 'p' => 0.1], ['n' => 'c', 'amt' => null, 'c' => 4, 'p' => 1.0]];
        $r = new ReportResult('T', [Column::text('n', 'Name'), Column::money('amt', 'Amount'), Column::int('c', 'Count'), Column::pct('p', 'Pct')], $rows, extraTotals: ['p' => 0.42]);
        self::assertSame(['p' => 0.42, 'amt' => 12.75, 'c' => 7], $r->totals);
        self::assertSame(['a', 'b', 'c'], array_column($r->sorted('n', 'asc'), 'n'));
        self::assertSame(['b', 'a', 'c'], array_column($r->sorted('amt', 'desc'), 'n'), 'empty values sort last');
        self::assertSame('12.75', Column::money('x', 'X')->format(12.75));
        self::assertSame('42.0%', Column::pct('x', 'X')->format(0.42));
        self::assertNull((new ReportResult('T', [Column::text('n', 'N')], $rows))->totals, 'no summable columns → no totals row');
    }

    public function testImportCellValuesAreNormalised(): void
    {
        $date = new ImportColumn('d', 'Date', type: 'date');
        $time = new ImportColumn('t', 'Time', type: 'time');
        $id = new ImportColumn('m', 'Mobile', type: 'id');
        self::assertSame('2026-11-01', SpreadsheetReader::value('01-11-2026', $date));
        self::assertSame('2026-11-01', SpreadsheetReader::value('01/11/2026', $date));
        self::assertSame('2026-11-01', SpreadsheetReader::value(46327, $date), 'Excel serial date');
        self::assertSame('31-02-2026', SpreadsheetReader::value('31-02-2026', $date), 'impossible dates stay as typed for the validator');
        self::assertSame('10:00', SpreadsheetReader::value(0.4166666667, $time));
        self::assertSame('09:30', SpreadsheetReader::value('9.30', $time));
        self::assertSame('234123412346', SpreadsheetReader::value(234123412346.0, $id), 'numbers keep all digits');
        self::assertSame('mobile number', ImportColumn::normalizeHeader('  Mobile Number * '));
        self::assertSame(ImportColumn::normalizeHeader('Aadhaar number'), ImportColumn::normalizeHeader('AADHAAR NUMBER (required)'));
    }

    public function testAuditDiffFlattensAndClassifies(): void
    {
        $d = AuditTrail::diff(['old_values' => json_encode(['amount' => 500, 'to' => null, 'meta' => ['a' => 1]]), 'new_values' => json_encode(['amount' => 550, 'from' => '2026-10-01', 'meta' => ['a' => 1], 'seats' => ['G-1', 'G-2']])]);
        $by = array_column($d, null, 'key');
        self::assertSame('changed', $by['amount']['state']);
        self::assertSame(['500', '550'], [$by['amount']['old'], $by['amount']['new']]);
        self::assertSame('added', $by['from']['state']);
        self::assertSame('same', $by['meta.a']['state']);
        self::assertSame('G-1, G-2', $by['seats']['new']);
        self::assertSame('removed', $by['to']['state']);
    }
}
