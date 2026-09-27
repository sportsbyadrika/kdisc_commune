<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BillingUnit;
use App\Enums\PaymentRule;
use App\Enums\SeatCategory;
use App\Services\Space\BookingPeriod;

/**
 * Immutable price quote produced by QuoteCalculator (JSON via toArray()).
 *
 * seats[]   one entry per bookable unit: {seat_id, code, label, unit_price, gst_rate, amount, components[], note}
 * addons[]  {facility_id, code, name, emoji, icon, unit, qty, unit_price, gst_rate, amount, note}
 * lines[]   display lines (seats grouped by identical pricing, then add-ons) each with GST split
 */
final class Quote
{
    /**
     * @param list<array<string, mixed>> $seats
     * @param list<array<string, mixed>> $addons
     * @param list<array<string, mixed>> $lines
     */
    public function __construct(
        public readonly SeatCategory $category,
        public readonly BookingPeriod $period,
        public readonly Duration $duration,
        public readonly BillingUnit $durationUnit,
        public readonly float $durationQty,
        public readonly array $seats,
        public readonly array $addons,
        public readonly array $lines,
        public readonly bool $interState,
        public readonly float $seatsTotal,
        public readonly float $addonsTotal,
        public readonly float $taxableTotal,
        public readonly float $cgst,
        public readonly float $sgst,
        public readonly float $igst,
        public readonly float $gstTotal,
        public readonly float $grandTotal,
        public readonly PaymentRule $paymentRule,
        public readonly float $depositAmount,
        public readonly float $payableNow,
        public readonly float $monthlyRent,
    ) {
    }

    public function seatCount(): int
    {
        return array_sum(array_map(static fn (array $s) => (int) ($s['capacity'] ?? 1), $this->seats));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => ['code' => $this->category->value, 'label' => $this->category->label(), 'short' => $this->category->shortLabel()],
            'period' => $this->period->toArray() + ['label' => $this->period->label()],
            'duration' => [
                'label' => $this->duration->label(),
                'months' => $this->duration->months,
                'days' => $this->duration->days,
                'total_days' => $this->duration->totalDays,
                'hours' => $this->duration->hours,
                'unit' => $this->durationUnit->value,
                'qty' => $this->durationQty,
            ],
            'seats' => $this->seats,
            'addons' => $this->addons,
            'lines' => $this->lines,
            'tax_mode' => $this->interState ? 'inter' : 'intra',
            'totals' => [
                'seats' => $this->seatsTotal,
                'addons' => $this->addonsTotal,
                'taxable' => $this->taxableTotal,
                'cgst' => $this->cgst,
                'sgst' => $this->sgst,
                'igst' => $this->igst,
                'gst' => $this->gstTotal,
                'grand' => $this->grandTotal,
            ],
            'payment' => [
                'rule' => $this->paymentRule->value,
                'label' => $this->paymentRule->label(),
                'deposit' => $this->depositAmount,
                'payable_now' => $this->payableNow,
                'monthly_rent' => $this->monthlyRent,
            ],
            'seat_count' => $this->seatCount(),
        ];
    }
}
