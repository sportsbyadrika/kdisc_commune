<?php

declare(strict_types=1);

namespace App\Enums;

/** A seat row is a single chair, a whole cabin (parent of chairs) or a room (parent of chairs). */
enum SeatKind: string
{
    use EnumHelpers;

    case Seat = 'seat';
    case Cabin = 'cabin';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Seat => 'Seat',
            self::Cabin => 'Cabin',
            self::Room => 'Room',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Seat => 'neutral',
            self::Cabin => 'brand',
            self::Room => 'info',
        };
    }
}
