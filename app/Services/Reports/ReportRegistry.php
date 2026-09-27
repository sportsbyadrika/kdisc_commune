<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Core\Container;
use App\Enums\StaffRole;
use App\Services\Finance\FinanceReportService;
use App\Services\Reports\Definitions\FinanceRegisterReport;

/**
 * Every report definition, keyed by Report::key(). The reports hub lists the `listed()` ones the role may open
 * (Report::ability()); list exports (visitors, bookings, payments, invoices) are reached from their pages.
 */
final class ReportRegistry
{
    /** @var list<class-string<Report>> */
    public const DEFINITIONS = [
        Definitions\OccupancyReport::class,
        Definitions\BookingsReport::class,
        Definitions\RenewalsReport::class,
        Definitions\RevenueReport::class,
        Definitions\CollectionsReport::class,
        Definitions\DuesAgeingReport::class,
        Definitions\GstSummaryReport::class,
        Definitions\KycFunnelReport::class,
        Definitions\DemographicsReport::class,
        Definitions\VisitorsList::class,
        Definitions\BookingsList::class,
        Definitions\PaymentsList::class,
        Definitions\InvoicesList::class,
    ];

    public const GROUPS = [
        'operations' => ['Occupancy & bookings', 'armchair'],
        'finance' => ['Revenue & finance', 'indian-rupee'],
        'visitors' => ['Visitors', 'users'],
        'lists' => ['List exports', 'list'],
    ];

    /** @var array<string, Report>|null */
    private ?array $reports = null;

    public function __construct(private readonly Container $container)
    {
    }

    /** @return array<string, Report> */
    public function all(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }
        $out = [];
        foreach (self::DEFINITIONS as $class) {
            /** @var Report $r */
            $r = $this->container->get($class);
            $out[$r->key()] = $r;
        }
        /** @var FinanceReportService $finance */
        $finance = $this->container->get(FinanceReportService::class);
        foreach (array_keys(FinanceReportService::REGISTERS) as $type) {
            $r = new FinanceRegisterReport($finance, $type);
            $out[$r->key()] = $r;
        }
        return $this->reports = $out;
    }

    public function find(string $key): ?Report
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Hub sections for a role: group => list of reports.
     *
     * @return array<string, list<Report>>
     */
    public function hub(StaffRole $role): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            if ($r->listed() && $role->can($r->ability())) {
                $out[$r->group()][] = $r;
            }
        }
        return array_replace(array_intersect_key(array_fill_keys(array_keys(self::GROUPS), []), $out), $out);
    }
}
