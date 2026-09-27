<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Enums\StaffRole;

/**
 * Who performs a booking action: a staff user (with role → abilities), a visitor account, or the system
 * (scheduled commands). Recorded in audit_logs as actor_type/actor_id.
 *
 *   Actor::staff($staffRow)   Actor::visitor($accountId, $customerId)   Actor::system()
 */
final class Actor
{
    private function __construct(
        public readonly string $type,
        public readonly ?int $id,
        public readonly ?StaffRole $role = null,
        public readonly ?int $customerId = null,
        public readonly string $name = '',
    ) {
    }

    /** @param array<string, mixed> $staff staff_users row */
    public static function staff(array $staff): self
    {
        return new self('staff', (int) $staff['id'], StaffRole::from((string) $staff['role']), null, (string) ($staff['name'] ?? ''));
    }

    public static function visitor(int $accountId, int $customerId, string $name = ''): self
    {
        return new self('account', $accountId, null, $customerId, $name);
    }

    public static function system(): self
    {
        return new self('system', null, null, null, 'System');
    }

    public function isStaff(): bool
    {
        return $this->type === 'staff';
    }

    public function isVisitor(): bool
    {
        return $this->type === 'account';
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }

    /** Staff ability check; the system may do anything, visitors nothing staff-only. */
    public function can(string $ability): bool
    {
        return $this->isSystem() || ($this->role !== null && $this->role->can($ability));
    }

    /** "staff:3", "account:12", "system" — stored in bookings.cancelled_by. */
    public function tag(): string
    {
        return $this->isSystem() ? 'system' : $this->type . ':' . $this->id;
    }

    public function staffId(): ?int
    {
        return $this->isStaff() ? $this->id : null;
    }
}
