<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\AmountInWords;
use App\Services\Finance\FinancialYear;
use App\Services\Finance\GstMath;
use App\Services\Finance\InvoiceBuilder;
use App\Services\Finance\NumberSequence;
use PHPUnit\Framework\TestCase;

/** Batch 6: financial year, amount in words (lakh / crore / paise), GST maths and invoice lines from a quote. */
final class FinanceMathTest extends TestCase
{
    public function testFinancialYearRollsOverOnFirstApril(): void
    {
        self::assertSame('2026-27', FinancialYear::of('2026-04-01'));
        self::assertSame('2026-27', FinancialYear::of('2027-03-31'));
        self::assertSame('2027-28', FinancialYear::of('2027-04-01'));
        self::assertSame('2025-26', FinancialYear::of('2026-03-31'));
        self::assertSame('2099-00', FinancialYear::of('2099-12-01'));
        self::assertSame(['2026-04-01', '2027-03-31'], FinancialYear::range('2026-27'));
        self::assertSame('2025-26', FinancialYear::previous('2026-27'));
        self::assertTrue(FinancialYear::valid('2026-27'));
        self::assertFalse(FinancialYear::valid('2026-28'));
        self::assertSame(['2026-27', '2025-26', '2024-25'], FinancialYear::recent('2026-09-27', 3));
    }

    public function testDocumentNumberFormatAndSlug(): void
    {
        self::assertSame('KDISC/CMN/2026-27/0001', NumberSequence::format(NumberSequence::INVOICE, '2026-27', 1, 'KDISC/CMN'));
        self::assertSame('RCPT/2026-27/0042', NumberSequence::format(NumberSequence::RECEIPT, '2026-27', 42, 'RCPT'));
        self::assertSame('CN/2027-28/12345', NumberSequence::format(NumberSequence::CREDIT_NOTE, '2027-28', 12345, 'CN'));
        self::assertSame('KDISC-CMN-2026-27-0001', NumberSequence::slug('KDISC/CMN/2026-27/0001'));
    }

    public function testAmountInWordsIndianSystem(): void
    {
        self::assertSame('Rupees Zero Only', AmountInWords::rupees(0));
        self::assertSame('Rupees One Only', AmountInWords::rupees(1));
        self::assertSame('Rupees Eleven Thousand Eight Hundred Only', AmountInWords::rupees(11800));
        self::assertSame('Rupees Thirty Thousand Six Hundred Eighty Only', AmountInWords::rupees('30680.00'));
        self::assertSame('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only', AmountInWords::rupees(123456.78));
        self::assertSame('Rupees Ten Lakh Only', AmountInWords::rupees(1000000));
        self::assertSame('Rupees Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine Only', AmountInWords::rupees(123456789));
        self::assertSame('Rupees One Hundred Twenty Crore Only', AmountInWords::rupees(1200000000));
        self::assertSame('Fifty Paise Only', AmountInWords::rupees(0.5));
        self::assertSame('Rupees Ten and Five Paise Only', AmountInWords::rupees(10.05));
        self::assertSame('Rupees One Hundred and One Paise Only', AmountInWords::rupees(100.006), 'rounded to paise first');
    }

    public function testGstSplitRoundsPerLine(): void
    {
        self::assertSame(['cgst' => 1080.0, 'sgst' => 1080.0, 'igst' => 0.0, 'tax' => 2160.0, 'total' => 14160.0], GstMath::split(12000, 18, false));
        self::assertSame(['cgst' => 0.0, 'sgst' => 0.0, 'igst' => 2160.0, 'tax' => 2160.0, 'total' => 14160.0], GstMath::split(12000, 18, true));
        // 333.33 × 9% = 29.9997 → 30.00 each; IGST 59.9994 → 60.00
        $intra = GstMath::split(333.33, 18, false);
        self::assertSame(30.0, $intra['cgst']);
        self::assertSame(393.33, $intra['total']);
        self::assertSame(60.0, GstMath::split(333.33, 18, true)['igst']);
        // 5.83 × 9% = 0.5247 → 0.52 + 0.52 = 1.04 intra-state, but 5.83 × 18% = 1.0494 → 1.05 IGST (each rounded to paise)
        self::assertSame(1.04, GstMath::split(5.83, 18, false)['tax']);
        self::assertSame(1.05, GstMath::split(5.83, 18, true)['tax']);
    }

    public function testAllocateAlwaysAddsUp(): void
    {
        $parts = GstMath::allocate([1, 1, 1], 100.0);
        self::assertEqualsWithDelta(100.0, array_sum($parts), 0.0001);
        self::assertSame([33.34, 33.33, 33.33], $parts);
        self::assertSame([0.0, 0.0], GstMath::allocate([0, 0], 0.0));
        self::assertSame([50.0, 50.0], GstMath::allocate([0, 0], 100.0));
    }

    public function testInvoiceLinesFromQuoteMatchTheBookingTotals(): void
    {
        $quote = self::quote();
        $lines = InvoiceBuilder::advance($quote, false, '997212', 'G-FX-04, G-FX-05');
        self::assertCount(3, $lines);
        self::assertSame('2 × Flexi seat · G-FX-04, G-FX-05', $lines[0]['description']);
        self::assertSame(12000.0, $lines[0]['rate']);
        self::assertSame('seat', $lines[0]['unit']);
        self::assertSame(900.0, $lines[1]['rate'], 'add-on rate = amount / qty');
        $t = InvoiceBuilder::finish($lines, 30680.0);
        self::assertSame(26000.0, $t['taxable']);
        self::assertSame(2340.0, $t['cgst']);
        self::assertSame(2340.0, $t['sgst']);
        self::assertSame(0.0, $t['igst']);
        self::assertSame(0.0, $t['round_off']);
        self::assertSame(30680.0, $t['total']);

        $igst = InvoiceBuilder::finish(InvoiceBuilder::advance($quote, true, '997212'), 30680.0);
        self::assertSame(0.0, $igst['cgst']);
        self::assertSame(4680.0, $igst['igst']);
    }

    public function testRentPeriodLinesSpreadThePeriodTaxable(): void
    {
        $lines = InvoiceBuilder::rent(self::quote(), ['period_no' => 2, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'taxable' => '8666.67'], 3, false, '997212');
        self::assertEqualsWithDelta(8666.67, array_sum(array_column($lines, 'taxable_value')), 0.0001);
        self::assertStringContainsString('Rent period 2 of 3', $lines[0]['detail']);
        // billed amount from the schedule differs by a paisa → recorded as round off
        $t = InvoiceBuilder::finish($lines, 10226.67);
        self::assertSame(round(10226.67 - ($t['total'] - $t['round_off']), 2), $t['round_off']);
        self::assertLessThan(1.0, abs($t['round_off']));
        self::assertSame(10226.67, $t['total']);
    }

    public function testMismatchedBillIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        InvoiceBuilder::finish(InvoiceBuilder::advance(self::quote(), false, '997212'), 25000.0);
    }

    /** @return array<string, mixed> */
    private static function quote(): array
    {
        return [
            'category' => ['code' => 'FLEXI', 'label' => 'Flexi / Hot desk'],
            'period' => ['label' => '01 Oct 2026 → 31 Dec 2026'],
            'lines' => [
                ['kind' => 'seat', 'label' => '2 × Flexi seat · G-FX-02, G-FX-03', 'detail' => '3 months × ₹4,000', 'qty' => 2, 'unit_amount' => 12000, 'amount' => 24000, 'gst_rate' => 18],
                ['kind' => 'addon', 'label' => 'Personal locker', 'detail' => '2 × ₹300/month × 3 months', 'qty' => 2, 'unit_amount' => 300, 'amount' => 1800, 'gst_rate' => 18],
                ['kind' => 'addon', 'label' => 'Printing pack', 'detail' => '₹200/use', 'qty' => 1, 'unit_amount' => 200, 'amount' => 200, 'gst_rate' => 18],
            ],
        ];
    }
}
