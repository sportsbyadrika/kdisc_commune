<?php

declare(strict_types=1);

namespace App\Models;

/** Authorised signatory / contact person of an institution (Aadhaar mandatory, spec 4.3). */
final class CustomerSignatory extends Model
{
    protected const TABLE = 'customer_signatories';

    /** @return array<string, mixed>|null primary signatory without the Aadhaar ciphertext/hash */
    public static function primaryFor(int $customerId): ?array
    {
        $row = static::db()->first(
            'SELECT * FROM customer_signatories WHERE customer_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1',
            [$customerId],
        );
        if ($row !== null) {
            unset($row['aadhaar_enc'], $row['aadhaar_hash']);
        }
        return $row;
    }
}
