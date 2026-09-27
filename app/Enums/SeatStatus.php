<?php

declare(strict_types=1);

namespace App\Enums;

/** Physical/administrative seat status (availability for dates is computed from bookings + holds). */
enum SeatStatus: string
{
    use EnumHelpers;

    case Available = 'available';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Blocked => 'Blocked',
            self::Maintenance => 'Under maintenance',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Blocked => 'neutral',
            self::Maintenance => 'warning',
        };
    }
}
