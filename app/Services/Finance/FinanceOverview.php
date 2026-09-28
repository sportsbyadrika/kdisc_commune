<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Support\Clock;
use DateTimeImmutable;

/**
 * Finance dashboard payload (spec 6.4): collections vs dues (this month / FY), month-by-month chart, revenue by
 * category and by facility, GST collected, verification + invoice queues, deposits held, recent invoices.
 * Pure composition of FinanceReportService queries (+ queue counts) — the chart JSON goes to resources/js/finance.js.
 */
final class FinanceOverview
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly PaymentVerificationService $verification,
        private readonly InvoiceService $invoices,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(?string $fy = null): array
    {
        $today = $this->clock->today();
        $fy ??= FinancialYear::of($today);
        [$fyStart, $fyEnd] = FinancialYear::range($fy);
        $monthStart = (new DateTimeImmutable($today))->format('Y-m-01');
        $monthEnd = (new DateTimeImmutable($today))->format('Y-m-t');
        $fyTo = min($fyEnd, $today);
        $monthly = $this->reports->monthly($fy);
        return [
            'fy' => $fy,
            'fys' => FinancialYear::recent($today, 4),
            'today' => $today,
            'month' => ['label' => (new DateTimeImmutable($today))->format('F Y'), 'collected' => $this->reports->collected($monthStart, $monthEnd), 'due' => $this->reports->due($monthStart, $monthEnd)],
            'year' => ['collected' => $this->reports->collected($fyStart, $fyTo), 'due' => $this->reports->due($fyStart, $fyTo)],
            'monthly' => $monthly,
            'byCategory' => $this->reports->revenueByCategory($fyStart, $fyEnd),
            'byFacility' => $this->reports->revenueByFacility($fyStart, $fyEnd),
            'gst' => $this->reports->gst($fyStart, $fyEnd),
            'verification' => $this->verification->counts(),
            'queue' => $this->invoices->queueCount(),
            'depositsHeld' => $this->reports->depositsHeld(),
            'outstanding' => $this->reports->outstandingTotals(),
            'recent' => $this->reports->recentInvoices(8),
            'chart' => [
                'labels' => array_column($monthly, 'label'),
                'due' => array_column($monthly, 'due'),
                'collected' => array_column($monthly, 'collected'),
                'verified' => array_column($monthly, 'verified'),
                'current' => array_search((new DateTimeImmutable($today))->format('Y-m'), array_column($monthly, 'month'), true),
            ],
        ];
    }
}
