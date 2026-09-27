<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Services\Bookings\Actor;

/**
 * State shared by the rows of ONE workbook while it is validated / imported: who is importing, the batch, and what
 * earlier rows of the same file already claimed (identities for duplicate checks, seats + dates, amounts paid per
 * booking, facility codes, rate targets) — so a file cannot contain the same visitor twice or double-book a seat.
 */
final class ImportContext
{
    /** @var array<string, array<string, int>> kind => value => first row number */
    private array $seen = [];
    /** @var array<string, mixed> free-form per-type state (seat claims, running totals) */
    public array $state = [];

    /** @param array<string, mixed> $staff staff_users row */
    public function __construct(
        public readonly array $staff,
        public readonly int $batchId,
        public readonly string $today,
        public readonly bool $sendInvites = false,
    ) {
    }

    public function actor(): Actor
    {
        return Actor::staff($this->staff);
    }

    public function staffId(): int
    {
        return (int) $this->staff['id'];
    }

    /**
     * Remember $value (e.g. an email) for $row; returns the earlier row number that used it, or null.
     */
    public function claim(string $kind, string $value, int $row): ?int
    {
        if ($value === '') {
            return null;
        }
        if (isset($this->seen[$kind][$value]) && $this->seen[$kind][$value] !== $row) {
            return $this->seen[$kind][$value];
        }
        $this->seen[$kind][$value] = $row;
        return null;
    }
}
