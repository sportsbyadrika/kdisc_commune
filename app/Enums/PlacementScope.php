<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a facility is attached on the map. */
enum PlacementScope: string
{
    use EnumHelpers;

    case Floor = 'floor';
    case Zone = 'zone';
    case Seat = 'seat';

    public function label(): string
    {
        return match ($this) {
            self::Floor => 'Floor',
            self::Zone => 'Zone',
            self::Seat => 'Seat',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Floor => 'neutral',
            self::Zone => 'neutral',
            self::Seat => 'neutral',
        };
    }
}
