<?php

declare(strict_types=1);

namespace App\Models;

final class SeatCategory extends Model
{
    protected const TABLE = 'seat_categories';

    /** @return array<string, mixed>|null */
    public static function findByCode(string $code): ?array
    {
        return static::firstWhere(['code' => $code]);
    }
}
