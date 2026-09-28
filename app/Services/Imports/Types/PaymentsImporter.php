<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Services\Bookings\BookingDirectory;
use App\Services\Imports\ImportColumn;
use App\Services\Imports\ImportContext;
use App\Services\Imports\Importer;
use App\Services\Payments\ConfirmationRule;
use App\Services\Payments\PaymentLedger;
use App\Services\Payments\PaymentService;

/**
 * Front-desk payments in bulk against EXISTING bookings (by booking no.). Rules: PaymentService::rules() + the same
 * checks PaymentService::log() makes (billable booking, reference required for non-cash modes, deposit only when the
 * booking has one, never more than the outstanding balance — counting earlier rows of this file). Imported through
 * PaymentService::log(): status "logged" (Finance verifies them and issues receipts), and bookings are confirmed
 * automatically once ConfirmationRule is met.
 */
final class PaymentsImporter extends Importer
{
    public function __construct(
        private readonly BookingDirectory $directory,
        private readonly PaymentLedger $ledger,
        private readonly PaymentService $payments,
    ) {
    }

    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Payments';
    }

    public function description(): string
    {
        return 'Payments received against existing bookings — logged for Finance to verify.';
    }

    public function icon(): string
    {
        return 'wallet';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('booking_no', 'Booking no.', true, 'BK-2026-000012', 'An existing booking (approved or later).', type: 'id', width: 17),
            new ImportColumn('kind', 'Payment for', true, 'Advance', 'Advance (≤ 6-month bookings), Security deposit, Rent or Add-on.', array_values(PaymentKind::options()), width: 16),
            new ImportColumn('mode', 'Mode', true, 'UPI', '', array_values(PaymentMode::deskOptions()), width: 14),
            new ImportColumn('reference_no', 'Reference', false, 'UPI/627118823301', 'UTR / transaction id / cheque no. — required for every mode except cash.', type: 'id', width: 22),
            new ImportColumn('amount', 'Amount (₹)', true, '15045', 'Rupees, not more than the balance outstanding.', type: 'number', width: 12),
            new ImportColumn('paid_on', 'Paid on', true, '26-09-2026', 'dd-mm-yyyy — today or earlier.', type: 'date', width: 12),
            new ImportColumn('remarks', 'Remarks', false, '', '', width: 24),
        ];
    }

    /** @return list<string> */
    public function instructions(): array
    {
        return [
            'Payments are logged exactly as at the front desk: Finance verifies them in "Verify payments" and issues the receipts.',
            'A booking is confirmed automatically once its required payment is logged (advance = grand total; security deposit bookings = deposit + first month).',
        ];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        $kind = self::option($row['kind'] ?? '', PaymentKind::options());
        $mode = self::option($row['mode'] ?? '', PaymentMode::deskOptions());
        $data = [
            'booking_no' => strtoupper(trim($row['booking_no'] ?? '')),
            'kind' => $kind ?? '', 'mode' => $mode ?? '',
            'reference_no' => trim($row['reference_no'] ?? ''),
            'amount' => str_replace([',', '₹', ' '], '', trim($row['amount'] ?? '')),
            'paid_on' => trim($row['paid_on'] ?? ''),
            'remarks' => trim($row['remarks'] ?? ''),
        ];
        try {
            Validator::make($data, PaymentService::rules(), [], ['reference_no' => 'reference', 'paid_on' => 'payment date', 'kind' => 'payment for'])->validate();
        } catch (ValidationException $e) {
            $errors = self::errorsFrom($e);
        }
        if (($row['kind'] ?? '') !== '' && $kind === null) {
            $errors['kind'] = 'Pick one of: ' . implode(', ', PaymentKind::options()) . '.';
        }
        if (($row['mode'] ?? '') !== '' && $mode === null) {
            $errors['mode'] = 'Pick one of: ' . implode(', ', PaymentMode::deskOptions()) . '.';
        }
        if ($mode !== null && PaymentMode::from($mode)->requiresReference() && $data['reference_no'] === '') {
            $errors['reference_no'] = sprintf('Enter the %s — required for %s payments.', PaymentMode::from($mode)->referenceLabel(), PaymentMode::from($mode)->label());
        }
        $booking = $data['booking_no'] !== '' ? $this->directory->findByNo($data['booking_no']) : null;
        $note = '';
        if ($data['booking_no'] !== '' && $booking === null) {
            $errors['booking_no'] = "No booking {$data['booking_no']}.";
        } elseif ($booking !== null) {
            $status = BookingStatus::from((string) $booking['status']);
            if (!$status->billable()) {
                $errors['booking_no'] = $status === BookingStatus::Requested
                    ? 'This booking is still a request — approve it before taking a payment.'
                    : sprintf('Booking %s is %s — payments cannot be logged against it.', $booking['booking_no'], strtolower($status->label()));
            } else {
                if ($kind === PaymentKind::Deposit->value && (float) $booking['deposit_amount'] <= 0) {
                    $errors['kind'] = 'This booking has no security deposit — log it as an advance.';
                }
                $dues = $this->ledger->dues($booking);
                $earlier = (float) ($ctx->state['paid'][$data['booking_no']] ?? 0);
                $left = round($dues['balance'] - $earlier, 2);
                if (is_numeric($data['amount']) && (float) $data['amount'] > $left + ConfirmationRule::TOLERANCE) {
                    $errors['amount'] = sprintf('More than the outstanding balance of %s%s.', money(max(0, $left), 2), $earlier > 0 ? ' (after earlier rows of this file)' : '');
                }
                if (!isset($errors['amount']) && is_numeric($data['amount'])) {
                    $ctx->state['paid'][$data['booking_no']] = $earlier + (float) $data['amount'];
                }
                $note = sprintf('%s · balance %s', $booking['customer_name'], money($dues['balance'], 2));
            }
        }
        return ['data' => $data, 'errors' => $errors, 'note' => $note];
    }

    public function import(array $data, ImportContext $ctx): string
    {
        $booking = $this->directory->findByNo((string) $data['booking_no']) ?? throw new \RuntimeException('Booking not found.');
        $r = $this->payments->log($booking, [
            'kind' => (string) $data['kind'], 'mode' => (string) $data['mode'], 'reference_no' => (string) $data['reference_no'],
            'amount' => (float) $data['amount'], 'paid_on' => (string) $data['paid_on'], 'remarks' => ($data['remarks'] !== '' ? $data['remarks'] . ' · ' : '') . 'bulk import #' . $ctx->batchId,
        ], $ctx->actor());
        return sprintf('Payment #%d · %s%s', (int) $r['payment']['id'], $booking['booking_no'], $r['confirmed'] ? ' · confirmed' : '');
    }
}
