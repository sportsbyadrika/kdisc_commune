<?php

declare(strict_types=1);

namespace App\Models;

/** KYC document metadata. Files live under storage/uploads (never public) — see App\Services\Kyc\DocumentStore. */
final class CustomerDocument extends Model
{
    protected const TABLE = 'customer_documents';

    /** @return list<array<string, mixed>> */
    public static function forCustomer(int $customerId): array
    {
        return static::db()->select('SELECT * FROM customer_documents WHERE customer_id = ? ORDER BY id', [$customerId]);
    }

    /**
     * Latest document per type.
     * @return array<string, array<string, mixed>>
     */
    public static function byType(int $customerId): array
    {
        $out = [];
        foreach (self::forCustomer($customerId) as $doc) {
            $out[(string) $doc['doc_type']] = $doc;
        }
        return $out;
    }
}
