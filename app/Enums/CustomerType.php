<?php

declare(strict_types=1);

namespace App\Enums;

/** Visitor (customer) type. Drives the Unique ID letter: I / N. */
enum CustomerType: string
{
    use EnumHelpers;

    case Individual = 'individual';
    case Institution = 'institution';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Institution => 'Institution',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Individual => 'brand',
            self::Institution => 'info',
        };
    }
}
