<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Seat category codes (seat_categories.code). Business rules per category
 * live here so every module applies them consistently (spec 5.2, 7.1).
 */
enum SeatCategory: string
{
    use EnumHelpers;

    case Flexi = 'FLEXI';
    case Dedicated = 'DEDICATED';
    case Cabin = 'CABIN';
    case Conference = 'CONF';

    public function label(): string
    {
        return match ($this) {
            self::Flexi => 'Flexi / Hot desk',
            self::Dedicated => 'Dedicated seat',
            self::Cabin => 'Executive cabin',
            self::Conference => 'Conference room',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Flexi => 'Flexi',
            self::Dedicated => 'Dedicated',
            self::Cabin => 'Cabin',
            self::Conference => 'Conference',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Flexi => 'success',
            self::Dedicated => 'brand',
            self::Cabin => 'info',
            self::Conference => 'warning',
        };
    }

    /** Seat-code segment used in auto numbering: G-FX-01, F-DD-12, G-CB-B, G-CF-F */
    public function codeSegment(): string
    {
        return match ($this) {
            self::Flexi => 'FX',
            self::Dedicated => 'DD',
            self::Cabin => 'CB',
            self::Conference => 'CF',
        };
    }

    /** @return list<BillingUnit> */
    public function billingUnits(): array
    {
        return match ($this) {
            self::Flexi => [BillingUnit::Day, BillingUnit::Month],
            self::Dedicated, self::Cabin => [BillingUnit::Month],
            self::Conference => [BillingUnit::Hour],
        };
    }

    /** Must be booked as a whole unit (all chairs of the cabin/room together). */
    public function wholeUnitOnly(): bool
    {
        return $this === self::Cabin || $this === self::Conference;
    }

    public function hourlyOnly(): bool
    {
        return $this === self::Conference;
    }

    /** Multi-select of several seats in one booking (institutions). */
    public function multiSelect(): bool
    {
        return $this === self::Dedicated || $this === self::Flexi;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Flexi => 'armchair',
            self::Dedicated => 'monitor',
            self::Cabin => 'door-open',
            self::Conference => 'presentation',
        };
    }
}
