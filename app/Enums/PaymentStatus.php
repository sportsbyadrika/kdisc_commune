<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment state. Front desk logs payments (logged); Finance verifies them (batch 6: receipts/invoices).
 * Payments are never deleted — a Centre Manager voids a wrong entry with a reason (void).
 */
enum PaymentStatus: string
{
    use EnumHelpers;

    case Logged = 'logged';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Refunded = 'refunded';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Logged => 'Logged',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Refunded => 'Refunded',
            self::Void => 'Void',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Logged => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::Refunded => 'neutral',
            self::Void => 'neutral',
        };
    }

    /** Counts towards dues / the confirmation requirement. */
    public function counts(): bool
    {
        return in_array($this, [self::Logged, self::Verified], true);
    }

    /** @return list<string> */
    public static function countingValues(): array
    {
        return [self::Logged->value, self::Verified->value];
    }
}
