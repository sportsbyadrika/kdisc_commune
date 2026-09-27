<?php

declare(strict_types=1);

namespace App\Enums;

/** GST invoice state. Invoices are never deleted. */
enum InvoiceStatus: string
{
    use EnumHelpers;

    case Issued = 'issued';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Issued => 'success',
            self::Cancelled => 'danger',
        };
    }
}
