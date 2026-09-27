<?php

declare(strict_types=1);

namespace App\Enums;

/** What a payment is for. */
enum PaymentKind: string
{
    use EnumHelpers;

    case Advance = 'advance';
    case Deposit = 'deposit';
    case Rent = 'rent';
    case Addon = 'addon';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Advance',
            self::Deposit => 'Security deposit',
            self::Rent => 'Rent',
            self::Addon => 'Add-on',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Advance => 'brand',
            self::Deposit => 'info',
            self::Rent => 'neutral',
            self::Addon => 'neutral',
        };
    }
}
