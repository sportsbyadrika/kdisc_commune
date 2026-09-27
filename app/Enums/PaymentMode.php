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
            self::BankTransfer => 'NEFT / RTGS',
            self::Cheque => 'Cheque',
            self::DemandDraft => 'Demand draft',
            self::Online => 'Online gateway',
        };
    }

    /** Every mode except cash needs a reference (UTR / transaction id / cheque no.). */
    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }

    public function referenceLabel(): string
    {
        return match ($this) {
            self::Upi => 'UPI transaction ID',
            self::BankTransfer => 'UTR number',
            self::Cheque => 'Cheque number',
            self::DemandDraft => 'DD number',
            self::Card => 'Card approval code',
            self::Online => 'Gateway reference',
            self::Cash => 'Reference (optional)',
        };
    }

    /**
     * Modes offered at the front desk (the gateway comes later).
     *
     * @return array<string, string>
     */
    public static function deskOptions(): array
    {
        $out = self::options();
        unset($out[self::Online->value]);
        return $out;
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
