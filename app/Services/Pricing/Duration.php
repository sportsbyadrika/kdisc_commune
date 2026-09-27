<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Space\BookingPeriod;
use DateTimeImmutable;

/**
 * Length of a booking period split into whole calendar months + remaining days (or hours).
 *
 *   1 Oct → 31 Oct  = 1 month 0 days          1 Oct → 15 Nov = 1 month 15 days
 *   1 Oct → 20 Oct  = 0 months 20 days        31 Jan → 27 Feb = 1 month (31 Jan + 1 month clamps to 28 Feb)
 *   1 Oct → 31 Mar  = 6 months exactly (Advance)     1 Oct → 1 Apr = 6 months 1 day (Security deposit)
 */
final class Duration
{
    public function __construct(
        public readonly int $months,
        public readonly int $days,
        public readonly int $totalDays,
        public readonly float $hours = 0.0,
    ) {
    }

    public static function of(BookingPeriod $period): self
    {
        if ($period->isHourly()) {
            return new self(0, 0, 1, $period->hourCount());
        }
        $from = new DateTimeImmutable($period->from);
        $to = new DateTimeImmutable($period->to);
        $months = 0;
        while (self::addMonths($from, $months + 1)->modify('-1 day') <= $to) {
            $months++;
        }
        $rest = (int) self::addMonths($from, $months)->diff($to)->days + 1;
        if (self::addMonths($from, $months) > $to) {
            $rest = 0;
        }
        return new self($months, $rest, $period->dayCount());
    }

    /** Adds calendar months, clamping to the month end (31 Jan + 1 month = 28/29 Feb). */
    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $y = (int) $date->format('Y');
        $m = (int) $date->format('n') + $months;
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        $d = min((int) $date->format('j'), (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
    }

    public function isHourly(): bool
    {
        return $this->hours > 0;
    }

    /** Months with the remaining days pro-rated (days / $daysPerMonth). */
    public function monthsEquivalent(int $daysPerMonth = 30): float
    {
        return round($this->months + $this->days / max(1, $daysPerMonth), 4);
    }

    /** Strictly longer than N months (6 months exactly is not). */
    public function exceedsMonths(int $n): bool
    {
        return $this->months > $n || ($this->months === $n && $this->days > 0);
    }

    public function label(): string
    {
        if ($this->isHourly()) {
            $h = $this->hours;
            return rtrim(rtrim(number_format($h, 1), '0'), '.') . ($h === 1.0 ? ' hour' : ' hours');
        }
        $parts = [];
        if ($this->months > 0) {
            $parts[] = $this->months . ($this->months === 1 ? ' month' : ' months');
        }
        if ($this->days > 0 || $parts === []) {
            $parts[] = $this->days . ($this->days === 1 ? ' day' : ' days');
        }
        return implode(' ', $parts);
    }
}
