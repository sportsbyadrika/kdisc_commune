<?php

declare(strict_types=1);

namespace App\Services\Finance;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Indian financial year (1 April → 31 March), written "2026-27". Invoice, receipt, credit-note and refund
 * voucher numbers restart every FY (spec 7.3): 31 Mar 2027 → 2026-27, 1 Apr 2027 → 2027-28.
 */
final class FinancialYear
{
    public static function of(string $date): string
    {
        $d = new DateTimeImmutable($date);
        $y = (int) $d->format('Y');
        $start = (int) $d->format('n') >= 4 ? $y : $y - 1;
        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    /** @return array{0: string, 1: string} first and last day (Y-m-d) */
    public static function range(string $fy): array
    {
        if (!self::valid($fy)) {
            throw new InvalidArgumentException("Invalid financial year [{$fy}].");
        }
        $start = (int) substr($fy, 0, 4);
        return [sprintf('%d-04-01', $start), sprintf('%d-03-31', $start + 1)];
    }

    public static function valid(string $fy): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $fy, $m)) {
            return false;
        }
        return ((int) $m[1] + 1) % 100 === (int) $m[2];
    }

    public static function previous(string $fy): string
    {
        [$start] = self::range($fy);
        return self::of((new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d'));
    }

    /** "FY 2026-27" */
    public static function label(string $fy): string
    {
        return 'FY ' . $fy;
    }

    /** @return list<string> the current FY and the $count - 1 before it, newest first */
    public static function recent(string $today, int $count = 4): array
    {
        $out = [self::of($today)];
        while (count($out) < $count) {
            $out[] = self::previous($out[count($out) - 1]);
        }
        return $out;
    }
}
