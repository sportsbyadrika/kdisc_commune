<?php

declare(strict_types=1);

namespace App\Enums;

/** How a payment was made. */
enum PaymentMode: string
{
    use EnumHelpers;

    case Cash = 'cash';
    case Upi = 'upi';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case DemandDraft = 'demand_draft';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Upi => 'UPI',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::DemandDraft => 'Demand draft',
            self::Online => 'Online gateway',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Cash => 'neutral',
            self::Upi => 'neutral',
            self::Card => 'neutral',
            self::BankTransfer => 'neutral',
            self::Cheque => 'neutral',
            self::DemandDraft => 'neutral',
            self::Online => 'neutral',
        };
    }
}
