<?php

declare(strict_types=1);

namespace App\Enums;

/** Layout version state (Designer: draft -> publish). */
enum LayoutStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Published => 'success',
            self::Archived => 'neutral',
        };
    }
}
