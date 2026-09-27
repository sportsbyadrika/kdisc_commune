<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Services\AuditLog;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Bookings\WorkflowException;
use App\Services\Kyc\DocumentStore;
use App\Support\Clock;

/**
 * Front-desk payment logging (spec 6.2, 7.2). Receptionist / Centre Manager log a payment against a booking →
 * status `logged` (Finance verifies it in batch 6 and issues the receipt — verified_by/verified_at stay empty
 * here). After every payment the booking is confirmed automatically once ConfirmationRule is met
 * (BookingWorkflow::confirmIfReady()). Payments are never deleted: a Centre Manager voids a wrong entry
 * with a reason (payments.void). Proof files (UPI screenshot, cheque scan) go through the secure upload
 * pipeline (DocumentStore::storeFile()) into storage/uploads/payments/{booking_id}/.
 */
final class PaymentService
{
    public function __construct(
        private readonly Database $db,
        private readonly PaymentLedger $ledger,
        private readonly BookingWorkflow $workflow,
        private readonly DocumentStore $files,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Validator rules for the payment form (shared by the controller and tests).
     *
     * @return array<string, string|list<string>>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', PaymentKind::rule()],
            'mode' => ['required', PaymentMode::rule()],
            'reference_no' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:1|max:99999999',
            'paid_on' => 'required|date|before_or_equal:today',
            'remarks' => 'nullable|string|max:500',
        ];
    }

    /**
     * @param array<string, mixed> $booking bookings row
     * @param array{kind: string, mode: string, reference_no?: ?string, amount: float|string, paid_on: string, remarks?: ?string} $data
     * @param array<string, mixed>|null $proof $_FILES entry (optional)
     * @return array{payment: array<string, mixed>, confirmed: bool, dues: array<string, mixed>}
     */
    public function log(array $booking, array $data, Actor $actor, ?array $proof = null, bool $trustedUpload = false): array
    {
        if (!$actor->can('payments.log')) {
            throw new WorkflowException('Your role cannot log payments.', 'permission');
        }
        $status = BookingStatus::from((string) $booking['status']);
        if (!$status->billable()) {
            throw new WorkflowException(match ($status) {
                BookingStatus::Requested => 'Approve the request before taking a payment.',
                default => sprintf('Booking %s is %s — payments cannot be logged against it.', $booking['booking_no'], strtolower($status->label())),
            });
        }
        $kind = PaymentKind::from((string) $data['kind']);
        $mode = PaymentMode::from((string) $data['mode']);
        $reference = trim((string) ($data['reference_no'] ?? ''));
        $amount = round((float) $data['amount'], 2);
        if ($mode->requiresReference() && $reference === '') {
            throw new ValidationException(['reference_no' => [sprintf('Enter the %s — it is required for %s payments.', $mode->referenceLabel(), $mode->label())]]);
        }
        if ($amount <= 0) {
            throw new ValidationException(['amount' => ['Enter an amount greater than zero.']]);
        }
        if ((string) $data['paid_on'] > $this->clock->today()) {
            throw new ValidationException(['paid_on' => ['The payment date cannot be in the future.']]);
        }
        if ($kind === PaymentKind::Deposit && (float) $booking['deposit_amount'] <= 0) {
            throw new ValidationException(['kind' => ['This booking has no security deposit — log it as an advance.']]);
        }
        $stored = null;
        if ($proof !== null && (int) ($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->files->storeFile($proof, 'payments/' . (int) $booking['id'], 'proof', $trustedUpload);
        }

        $payment = $this->db->transaction(function (Database $db) use ($booking, $kind, $mode, $reference, $amount, $data, $actor, $stored): array {
            $db->select('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [(int) $booking['id']]);
            $fresh = (array) $db->first('SELECT * FROM bookings WHERE id = ?', [(int) $booking['id']]);
            $dues = $this->ledger->dues($fresh);
            if ($amount > $dues['balance'] + ConfirmationRule::TOLERANCE) {
                throw new ValidationException(['amount' => [sprintf('That is more than the outstanding balance of %s.', money($dues['balance'], 2))]]);
            }
            $id = $db->insert('payments', [
                'booking_id' => (int) $fresh['id'],
                'customer_id' => (int) $fresh['customer_id'],
                'kind' => $kind->value,
                'mode' => $mode->value,
                'reference_no' => $reference !== '' ? mb_substr($reference, 0, 100) : null,
                'amount' => $amount,
                'paid_on' => (string) $data['paid_on'],
                'status' => PaymentStatus::Logged->value,
                'logged_by' => $actor->staffId(),
                'remarks' => ($data['remarks'] ?? '') !== '' ? mb_substr((string) $data['remarks'], 0, 500) : null,
                'proof_path' => $stored['path'] ?? null,
                'proof_mime' => $stored['mime'] ?? null,
                'proof_name' => $stored['name'] ?? null,
            ]);
            $this->audit->record('payment.log', 'booking', (int) $fresh['id'], null, [
                'payment_id' => $id, 'kind' => $kind->value, 'mode' => $mode->value, 'amount' => $amount, 'reference_no' => $reference, 'paid_on' => $data['paid_on'],
            ], null, $actor->type, $actor->id);
            return (array) $db->first('SELECT * FROM payments WHERE id = ?', [$id]);
        });
        logger()->info('Payment #{id} of {amount} logged on booking {no}', ['id' => $payment['id'], 'amount' => $amount, 'no' => $booking['booking_no']]);

        $confirmed = $this->workflow->confirmIfReady((int) $booking['id'], $actor);
        $after = $this->workflow->find((int) $booking['id']);
        return ['payment' => $payment, 'confirmed' => $confirmed, 'dues' => $this->ledger->dues($after)];
    }

    /**
     * Void a logged payment (never deleted). Verified payments are Finance's (credit note / refund).
     *
     * @return array<string, mixed>
     */
    public function void(int $paymentId, Actor $actor, string $reason): array
    {
        if (!$actor->can('payments.void')) {
            throw new WorkflowException('Only a Centre Manager can void a payment.', 'permission');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new WorkflowException('Please give a reason (at least 5 characters).', 'input');
        }
        return $this->db->transaction(function (Database $db) use ($paymentId, $actor, $reason): array {
            $p = $db->first('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw new WorkflowException('Payment not found.', 'input');
            if ($p['status'] !== PaymentStatus::Logged->value) {
                throw new WorkflowException(sprintf('This payment is %s — only logged (unverified) payments can be voided here.', strtolower(PaymentStatus::from((string) $p['status'])->label())));
            }
            $db->update('payments', ['status' => PaymentStatus::Void->value, 'void_reason' => mb_substr($reason, 0, 500), 'voided_by' => $actor->staffId(), 'voided_at' => $this->clock->sql()], ['id' => $paymentId]);
            $this->audit->record('payment.void', 'booking', (int) $p['booking_id'], ['payment_id' => $paymentId, 'status' => $p['status'], 'amount' => $p['amount']], ['status' => PaymentStatus::Void->value], $reason, $actor->type, $actor->id);
            return (array) $db->first('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        });
    }

    /**
     * Absolute path of a payment's proof file (refuses paths outside storage/uploads).
     *
     * @param array<string, mixed> $payment
     */
    public function proofPath(array $payment): string
    {
        return $this->files->path(['file_path' => (string) $payment['proof_path']]);
    }
}
