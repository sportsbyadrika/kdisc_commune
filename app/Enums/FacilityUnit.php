<?php

declare(strict_types=1);

namespace App\Enums;

/** Pricing unit of a chargeable facility. */
enum FacilityUnit: string
{
    use EnumHelpers;

    case Month = 'month';
    case Day = 'day';
    case Use = 'use';
    case Hour = 'hour';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'per month',
            self::Day => 'per day',
            self::Use => 'per use',
            self::Hour => 'per hour',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Month => 'neutral',
            self::Day => 'neutral',
            self::Use => 'neutral',
            self::Hour => 'neutral',
        };
    }
}
