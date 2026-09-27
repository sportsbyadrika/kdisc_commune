<?php

declare(strict_types=1);

namespace App\Enums;

/** Booking lifecycle (spec 8): requested -> approved -> confirmed -> active -> completed; or cancelled / rejected. */
enum BookingStatus: string
{
    use EnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Confirmed => 'Confirmed',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Confirmed => 'brand',
            self::Active => 'success',
            self::Completed => 'neutral',
            self::Cancelled => 'neutral',
            self::Rejected => 'danger',
        };
    }
}
