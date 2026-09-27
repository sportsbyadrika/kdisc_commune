<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Enums\KycStatus;
use App\Models\Account;
use App\Services\AuditLog;
use App\Services\Notify\Mailer;

/**
 * Centre Manager KYC decisions (spec 4.1 step 5). Every decision is audit-logged and emailed to the visitor.
 *
 *   $review->approve($customerId, $staffId, 'Originals seen at desk');
 *   $review->reject($customerId, $staffId, 'PAN card image is unreadable — please upload a clearer copy.');
 */
final class KycReviewService
{
    public function __construct(private readonly Database $db, private readonly AuditLog $audit, private readonly Mailer $mailer)
    {
    }

    public function approve(int $customerId, int $staffId, ?string $remarks = null): void
    {
        $customer = $this->decide($customerId, $staffId, KycStatus::Verified, $remarks);
        $this->notify($customer, 'Your Commune KYC is verified', 'kyc-approved', ['remarks' => $remarks]);
    }

    public function reject(int $customerId, int $staffId, string $reason): void
    {
        $customer = $this->decide($customerId, $staffId, KycStatus::Rejected, $reason);
        $this->notify($customer, 'Action needed: your Commune KYC', 'kyc-rejected', ['reason' => $reason]);
    }

    /** @return array<string, mixed> updated customer */
    private function decide(int $customerId, int $staffId, KycStatus $status, ?string $remarks): array
    {
        return $this->db->transaction(function (Database $db) use ($customerId, $staffId, $status, $remarks): array {
            $row = $db->first('SELECT * FROM customers WHERE id = ? FOR UPDATE', [$customerId]) ?? throw new HttpException(404);
            if ($row['kyc_status'] !== KycStatus::Pending->value) {
                throw new HttpException(409, 'This profile is no longer waiting for verification (status: ' . KycStatus::from((string) $row['kyc_status'])->label() . ').');
            }
            $now = date('Y-m-d H:i:s');
            $cols = ['kyc_status' => $status->value, 'kyc_remarks' => $remarks !== null && trim($remarks) !== '' ? mb_substr(trim($remarks), 0, 500) : null];
            if ($status === KycStatus::Verified) {
                $cols += ['kyc_verified_by' => $staffId, 'kyc_verified_at' => $now];
                $db->execute('UPDATE customer_documents SET verified_by = ?, verified_at = ? WHERE customer_id = ?', [$staffId, $now, $customerId]);
            }
            $db->update('customers', $cols, ['id' => $customerId]);
            $this->audit->record(
                $status === KycStatus::Verified ? 'kyc.approve' : 'kyc.reject',
                'customer',
                $customerId,
                ['kyc_status' => $row['kyc_status']],
                ['kyc_status' => $status->value],
                $cols['kyc_remarks'],
                'staff',
                $staffId,
            );
            return array_merge($row, $cols);
        });
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $data
     */
    private function notify(array $customer, string $subject, string $template, array $data): void
    {
        $account = $customer['account_id'] !== null ? Account::find((int) $customer['account_id']) : null;
        $email = (string) ($account['email'] ?? $customer['email'] ?? '');
        if ($email === '') {
            return;
        }
        $this->mailer->send([$email, (string) $customer['name']], $subject, $template, $data + [
            'name' => (string) $customer['name'],
            'uniqueId' => $customer['unique_id'],
            'portalUrl' => $account !== null ? absolute_url('portal.dashboard') : null,
            'wizardUrl' => $account !== null ? absolute_url('portal.wizard', ['step' => 1]) : null,
        ]);
    }
}
