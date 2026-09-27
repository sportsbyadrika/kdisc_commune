<?php

declare(strict_types=1);

namespace App\Enums;

/** KYC lifecycle of a customer. */
enum KycStatus: string
{
    use EnumHelpers;

    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => 'Not submitted',
            self::Pending => 'KYC pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NotSubmitted => 'neutral',
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
        };
    }
}
