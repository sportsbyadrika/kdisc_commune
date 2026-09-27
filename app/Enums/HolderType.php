<?php

declare(strict_types=1);

namespace App\Enums;

/** Who holds a seat or owns a token (visitor account vs staff user). */
enum HolderType: string
{
    use EnumHelpers;

    case Account = 'account';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Visitor',
            self::Staff => 'Staff',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Account => 'neutral',
            self::Staff => 'neutral',
        };
    }
}
