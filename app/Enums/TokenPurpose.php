<?php

declare(strict_types=1);

namespace App\Enums;

/** password_tokens.purpose */
enum TokenPurpose: string
{
    use EnumHelpers;

    case Set = 'set';
    case Reset = 'reset';
    case Invite = 'invite';

    public function label(): string
    {
        return match ($this) {
            self::Set => 'Set password',
            self::Reset => 'Reset password',
            self::Invite => 'Portal invite',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Set => 'neutral',
            self::Reset => 'neutral',
            self::Invite => 'neutral',
        };
    }
}
