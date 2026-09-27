<?php

declare(strict_types=1);

namespace App\Services\Finance;

/**
 * Rupee amounts in words with the Indian numbering system (thousand, lakh, crore) as printed on GST invoices:
 *
 *   11800      → "Rupees Eleven Thousand Eight Hundred Only"
 *   123456.78  → "Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only"
 *   0.5        → "Fifty Paise Only"
 *
 * Amounts are rounded to paise first. Crores above 99 are spelled recursively ("One Hundred Twenty Crore").
 */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(float|int|string $amount): string
    {
        $paiseTotal = (int) round(abs((float) $amount) * 100);
        $rupees = intdiv($paiseTotal, 100);
        $paise = $paiseTotal % 100;
        $minus = (float) $amount < 0 && $paiseTotal > 0 ? 'Minus ' : '';
        if ($rupees === 0 && $paise > 0) {
            return $minus . self::number($paise) . ' Paise Only';
        }
        $words = 'Rupees ' . ($rupees === 0 ? 'Zero' : self::number($rupees));
        if ($paise > 0) {
            $words .= ' and ' . self::number($paise) . ' Paise';
        }
        return $minus . $words . ' Only';
    }

    /** Whole number in Indian-system words ("One Crore Two Lakh Three Thousand"). 0 → "Zero". */
    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        $crore = intdiv($n, 10_000_000);
        $n %= 10_000_000;
        $lakh = intdiv($n, 100_000);
        $n %= 100_000;
        $thousand = intdiv($n, 1000);
        $n %= 1000;
        $hundred = intdiv($n, 100);
        $rest = $n % 100;
        if ($crore > 0) {
            $parts[] = self::number($crore) . ' Crore';
        }
        if ($lakh > 0) {
            $parts[] = self::twoDigits($lakh) . ' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = self::twoDigits($thousand) . ' Thousand';
        }
        if ($hundred > 0) {
            $parts[] = self::ONES[$hundred] . ' Hundred';
        }
        if ($rest > 0) {
            $parts[] = self::twoDigits($rest);
        }
        return implode(' ', $parts);
    }

    private static function twoDigits(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}
