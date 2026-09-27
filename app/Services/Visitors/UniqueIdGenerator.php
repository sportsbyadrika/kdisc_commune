<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Core\Database;
use App\Enums\CustomerType;

/**
 * Unique Visitor ID: CMN-KTR-{I|N}-{YYYY}-{00001} (I = individual, N = institution), issued when a
 * profile is submitted for KYC (spec 4.1 step 5). Numbers restart every calendar year per type.
 *
 * Gap-free and race-safe: the counter row in number_sequences (name "visitor_I" / "visitor_N",
 * period "2026") is locked with SELECT … FOR UPDATE inside the caller's transaction.
 */
final class UniqueIdGenerator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function next(CustomerType $type, ?int $year = null): string
    {
        $year ??= (int) date('Y');
        $letter = self::letter($type);
        $seq = $this->db->transaction(function (Database $db) use ($letter, $year): int {
            $name = 'visitor_' . $letter;
            $db->execute('INSERT IGNORE INTO number_sequences (name, period, last_value) VALUES (?, ?, 0)', [$name, (string) $year]);
            $last = (int) $db->scalar('SELECT last_value FROM number_sequences WHERE name = ? AND period = ? FOR UPDATE', [$name, (string) $year]);
            $db->execute('UPDATE number_sequences SET last_value = ? WHERE name = ? AND period = ?', [$last + 1, $name, (string) $year]);
            return $last + 1;
        });
        return self::format($type, $year, $seq);
    }

    public static function format(CustomerType $type, int $year, int $seq, ?string $prefix = null): string
    {
        $prefix ??= self::prefix();
        return sprintf('%s-%s-%04d-%05d', $prefix, self::letter($type), $year, $seq);
    }

    public static function letter(CustomerType $type): string
    {
        return $type === CustomerType::Individual ? 'I' : 'N';
    }

    private static function prefix(): string
    {
        try {
            $p = (string) setting('visitor_id_prefix', 'CMN-KTR');
        } catch (\Throwable) {
            $p = 'CMN-KTR';
        }
        return $p !== '' ? $p : 'CMN-KTR';
    }
}
