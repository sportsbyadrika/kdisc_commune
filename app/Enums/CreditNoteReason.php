<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a credit note was issued against a GST invoice (spec 6.4). */
enum CreditNoteReason: string
{
    use EnumHelpers;

    case Cancellation = 'cancellation';
    case EarlyExit = 'early_exit';
    case Handover = 'handover';
    case Discount = 'discount';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cancellation => 'Booking cancellation',
            self::EarlyExit => 'Early exit',
            self::Handover => 'Handover price difference',
            self::Discount => 'Discount / goodwill',
            self::Other => 'Other correction',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Cancellation => 'danger',
            self::EarlyExit => 'warning',
            self::Handover => 'info',
            self::Discount => 'success',
            self::Other => 'neutral',
        };
    }
}
