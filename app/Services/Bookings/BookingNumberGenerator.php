<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;

/**
 * Booking numbers BK-{YYYY}-{000001} (spec 7.3): counter row "booking"/{year} in number_sequences,
 * locked with SELECT … FOR UPDATE inside the caller's transaction (gap-free per year).
 */
final class BookingNumberGenerator
{
    public function __construct(private readonly Database $db)
    {
    }

    public function next(?int $year = null): string
    {
        $year ??= (int) date('Y');
        $seq = $this->db->transaction(function (Database $db) use ($year): int {
            $db->execute("INSERT INTO number_sequences (name, period, last_value) VALUES ('booking', ?, 0) ON DUPLICATE KEY UPDATE last_value = last_value", [(string) $year]); // X lock, no S→X upgrade deadlock
            $last = (int) $db->scalar("SELECT last_value FROM number_sequences WHERE name = 'booking' AND period = ? FOR UPDATE", [(string) $year]);
            $db->execute("UPDATE number_sequences SET last_value = ? WHERE name = 'booking' AND period = ?", [$last + 1, (string) $year]);
            return $last + 1;
        });
        return self::format($year, $seq);
    }

    public static function format(int $year, int $seq, ?string $prefix = null): string
    {
        if ($prefix === null) {
            try {
                $prefix = (string) setting('booking_prefix', 'BK');
            } catch (\Throwable) {
                $prefix = 'BK';
            }
        }
        return sprintf('%s-%04d-%06d', $prefix !== '' ? $prefix : 'BK', $year, $seq);
    }
}
