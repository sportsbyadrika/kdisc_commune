<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Enums\HolderType;

/**
 * Who is holding seats while choosing: a signed-in visitor account or a staff user, plus the
 * session id (two tabs of one session share a selection; two browsers do not).
 * Staff may pick seats on behalf of a visitor ($customerId) and, for managers, override
 * blocked/held seats ($canOverride).
 */
final class SeatHolder
{
    public function __construct(
        public readonly HolderType $type,
        public readonly int $id,
        public readonly string $sessionId,
        public readonly ?int $customerId = null,
        public readonly bool $canOverride = false,
    ) {
    }

    public static function account(int $accountId, string $sessionId): self
    {
        return new self(HolderType::Account, $accountId, $sessionId);
    }

    public static function staff(int $staffId, string $sessionId, ?int $customerId = null, bool $canOverride = false): self
    {
        return new self(HolderType::Staff, $staffId, $sessionId, $customerId, $canOverride);
    }

    public function isStaff(): bool
    {
        return $this->type === HolderType::Staff;
    }
}
