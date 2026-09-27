<?php

declare(strict_types=1);

namespace App\Enums;

/** Scope of a rate row. Resolution: seat ?? zone ?? category (spec 5.5). */
enum RateScope: string
{
    use EnumHelpers;

    case Category = 'category';
    case Zone = 'zone';
    case Seat = 'seat';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Category',
            self::Zone => 'Zone',
            self::Seat => 'Seat',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Category => 'neutral',
            self::Zone => 'neutral',
            self::Seat => 'neutral',
        };
    }
}
