<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a booking / registration originated. */
enum BookingSource: string
{
    use EnumHelpers;

    case Online = 'online';
    case Reception = 'reception';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Reception => 'Reception',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Online => 'info',
            self::Reception => 'brand',
        };
    }
}
