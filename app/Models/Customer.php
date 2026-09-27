<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\KycStatus;

/**
 * Visitor profile (individual / institution). aadhaar_enc is binary ciphertext — views get
 * aadhaar_last4 only (see App\Services\Kyc\AadhaarVault).
 */
final class Customer extends Model
{
    protected const TABLE = 'customers';

    /** @return array<string, mixed>|null */
    public static function findByAccount(int $accountId): ?array
    {
        return static::firstWhere(['account_id' => $accountId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUniqueId(string $uniqueId): ?array
    {
        return static::firstWhere(['unique_id' => strtoupper($uniqueId)]);
    }

    /**
     * Staff URLs use the Unique Visitor ID, or the numeric id for profiles not yet submitted.
     * @return array<string, mixed>|null
     */
    public static function findByRef(string $ref): ?array
    {
        return ctype_digit($ref) ? static::find((int) $ref) : static::findByUniqueId($ref);
    }

    /** @param array<string, mixed> $customer */
    public static function ref(array $customer): string
    {
        return (string) ($customer['unique_id'] ?? '') !== '' ? (string) $customer['unique_id'] : (string) $customer['id'];
    }

    /** @param array<string, mixed> $customer */
    public static function type(array $customer): CustomerType
    {
        return CustomerType::from((string) $customer['type']);
    }

    /** @param array<string, mixed> $customer */
    public static function kyc(array $customer): KycStatus
    {
        return KycStatus::from((string) $customer['kyc_status']);
    }

    /** @param array<string, mixed> $customer */
    public static function isForeign(array $customer): bool
    {
        return mb_strtolower(trim((string) ($customer['nationality'] ?? 'Indian'))) !== 'indian';
    }

    /**
     * Strip secrets before handing a row to a view.
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    public static function safe(array $customer): array
    {
        unset($customer['aadhaar_enc'], $customer['aadhaar_hash']);
        return $customer;
    }
}
