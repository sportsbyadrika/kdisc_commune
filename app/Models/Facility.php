<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FacilityKind;

final class Facility extends Model
{
    protected const TABLE = 'facilities';

    /** @return list<array<string, mixed>> */
    public static function active(?FacilityKind $kind = null): array
    {
        $conditions = ['is_active' => 1];
        if ($kind !== null) {
            $conditions['kind'] = $kind->value;
        }
        return static::where($conditions, 'sort_order');
    }
}
