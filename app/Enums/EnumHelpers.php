<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shared helpers for backed enums. Every enum in this folder implements label().
 * Optional tone() returns a badge tone: neutral|brand|success|warning|danger|info.
 */
trait EnumHelpers
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * value => label, for <select> options.
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }

    /** "in:" validation rule string for this enum. */
    public static function rule(): string
    {
        return 'in:' . implode(',', self::values());
    }

    public function tone(): string
    {
        return 'neutral';
    }
}
