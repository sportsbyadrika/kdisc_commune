<?php

declare(strict_types=1);

namespace App\Enums;

/** Rate / booking duration unit. */
enum BillingUnit: string
{
    use EnumHelpers;

    case Day = 'day';
    case Month = 'month';
    case Hour = 'hour';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Month => 'Month',
            self::Hour => 'Hour',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Day => 'neutral',
            self::Month => 'neutral',
            self::Hour => 'neutral',
        };
    }
}
