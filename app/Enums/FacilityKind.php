<?php

declare(strict_types=1);

namespace App\Enums;

/** Facility master kinds (spec 5.3). */
enum FacilityKind: string
{
    use EnumHelpers;

    case Included = 'included';
    case Addon = 'addon';
    case Landmark = 'landmark';

    public function label(): string
    {
        return match ($this) {
            self::Included => 'Included',
            self::Addon => 'Add-on',
            self::Landmark => 'Landmark',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Included => 'success',
            self::Addon => 'brand',
            self::Landmark => 'neutral',
        };
    }
}
