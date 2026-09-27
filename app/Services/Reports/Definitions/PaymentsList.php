<?php

declare(strict_types=1);

namespace App\Services\Reports\Definitions;

use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Services\Finance\PaymentVerificationService;
use App\Services\Reports\Column;
use App\Services\Reports\Filter;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;

/** Export of the payment verification queue with its filters — rows from PaymentVerificationService::queue(). */
final class PaymentsList extends Report
{
    public const STATUSES = ['pending' => 'To verify', 'queried' => 'Queried', 'verified' => 'Verified', 'void' => 'Void / rejected', 'all' => 'All'];

    public function __construct(private readonly PaymentVerificationService $verification)
    {
    }

    public function key(): string
    {
        return 'payments';
    }

    public function title(): string
    {
        return 'Payments';
    }

    public function description(): string
    {
        return 'Payments with booking, mode, reference, verification and receipt.';
    }

    public function group(): string
    {
        return 'lists';
    }

    public function icon(): string
    {
        return 'wallet';
    }

    public function ability(): string
    {
        return 'payments.view';
    }

    public function listed(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [
            Filter::select('status', 'Status', self::STATUSES, 'pending'),
            Filter::select('mode', 'Mode', PaymentMode::options()),
            Filter::select('kind', 'For', PaymentKind::options()),
            Filter::search('q'),
            Filter::date('from', 'Paid from'),
            Filter::date('to', 'Paid to'),
            Filter::select('sort', 'Sort', PaymentVerificationService::SORTS, 'oldest'),
        ];
    }

    public function run(ReportFilters $f): ReportResult
    {
        $rows = $this->verification->queue($f->all(), 1, VisitorsList::MAX_ROWS)['rows'];
        $rows = array_map(static fn (array $p) => $p + [
            'kind_label' => PaymentKind::tryFrom((string) $p['kind'])?->label() ?? '', 'mode_label' => PaymentMode::tryFrom((string) $p['mode'])?->label() ?? '',
            'status_label' => PaymentStatus::tryFrom((string) $p['status'])?->label() ?? (string) $p['status'],
        ], $rows);
        return new ReportResult('Payments — ' . self::STATUSES[$f->get('status', 'pending')], [
            Column::date('paid_on', 'Paid on'), Column::mono('booking_no', 'Booking', 16), Column::text('customer_name', 'Visitor', 26), Column::mono('unique_id', 'Unique ID', 22),
            Column::text('kind_label', 'For', 14), Column::text('mode_label', 'Mode', 13), Column::mono('reference_no', 'Reference', 22), Column::money('amount', 'Amount (₹)'),
            Column::text('status_label', 'Status', 11), Column::text('logged_by_name', 'Logged by', 18), Column::text('verified_by_name', 'Verified by', 18),
            Column::datetime('verified_at', 'Verified at'), Column::mono('receipt_no', 'Receipt', 20),
        ], $rows, 'Payments');
    }

    public function fileStem(ReportFilters $filters): string
    {
        return 'payments-' . $filters->get('status', 'pending') . '-' . $filters->today;
    }
}
