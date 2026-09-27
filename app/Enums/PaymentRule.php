<?php

declare(strict_types=1);

namespace App\Enums;

/** Payment rule: tenure <= 6 months -> advance; > 6 months -> security deposit (spec 7.2). */
enum PaymentRule: string
{
    use EnumHelpers;

    case Advance = 'advance';
    case SecurityDeposit = 'security_deposit';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Advance payment',
            self::SecurityDeposit => 'Security deposit',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Advance => 'brand',
            self::SecurityDeposit => 'info',
        };
    }
}
