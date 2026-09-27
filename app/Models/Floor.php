<?php

declare(strict_types=1);

namespace App\Models;

final class Floor extends Model
{
    protected const TABLE = 'floors';

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return static::firstWhere(['slug' => $slug]);
    }

    /** Published layout version id for a floor. */
    public static function publishedLayoutId(int $floorId): ?int
    {
        $id = static::db()->scalar(
            "SELECT id FROM layout_versions WHERE floor_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1",
            [$floorId],
        );
        return $id === null ? null : (int) $id;
    }
}
