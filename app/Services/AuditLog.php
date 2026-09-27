<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;

/**
 * Append-only audit trail (spec 12): money, pricing, layout, KYC document access, logins.
 *   $audit->record('rate.update', 'rate', $id, $old, $new, reason: 'Festival offer');
 */
final class AuditLog
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?string $actorType = null,
        ?int $actorId = null,
    ): void {
        if ($actorType === null) {
            $staff = App::guard('staff')->id();
            [$actorType, $actorId] = $staff !== null ? ['staff', $staff] : ['system', null];
        }
        $request = App::request();
        $this->db->insert('audit_logs', [
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'reason' => $reason,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
