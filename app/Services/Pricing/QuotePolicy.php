<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\SettingsService;

/**
 * Pricing knobs read from `settings` (all still to be confirmed by K-DISC — see spec §13).
 *
 * flexiRule:
 *   monthly_plus_daily (default)  < 1 month: daily rate × days; ≥ 1 month: monthly rate × whole months
 *                                 + remaining days × daily rate
 *   monthly_prorata               monthly rate × (months + days / daysPerMonth)
 *   daily_only                    daily rate × days
 * flexiCapMonthly: the daily-rate part never costs more than one month's rate (so 25 days never costs
 *   more than a month).
 */
final class QuotePolicy
{
    public const FLEXI_MONTHLY_PLUS_DAILY = 'monthly_plus_daily';
    public const FLEXI_MONTHLY_PRORATA = 'monthly_prorata';
    public const FLEXI_DAILY_ONLY = 'daily_only';

    public function __construct(
        public readonly int $advanceMaxMonths = 6,
        public readonly int $depositMonths = 2,
        public readonly string $flexiRule = self::FLEXI_MONTHLY_PLUS_DAILY,
        public readonly bool $flexiCapMonthly = true,
        public readonly int $daysPerMonth = 30,
        public readonly string $homeStateCode = '32',
    ) {
    }

    public static function fromSettings(SettingsService $s): self
    {
        return new self(
            advanceMaxMonths: (int) $s->get('advance_max_months', 6),
            depositMonths: (int) $s->get('security_deposit_months', 2),
            flexiRule: (string) $s->get('flexi_pricing_rule', self::FLEXI_MONTHLY_PLUS_DAILY),
            flexiCapMonthly: (bool) $s->get('flexi_daily_cap_monthly', true),
            daysPerMonth: max(1, (int) $s->get('proration_days_per_month', 30)),
            homeStateCode: (string) $s->get('home_state_code', '32'),
        );
    }

    /** Inter-state supply (IGST) when the customer's GST state code is known and differs from ours. */
    public function isInterState(?string $customerStateCode): bool
    {
        $code = trim((string) $customerStateCode);
        return $code !== '' && str_pad($code, 2, '0', STR_PAD_LEFT) !== str_pad($this->homeStateCode, 2, '0', STR_PAD_LEFT);
    }
}
