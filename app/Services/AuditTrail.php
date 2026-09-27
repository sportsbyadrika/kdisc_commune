<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Read side of audit_logs for the audit viewer (/staff/audit): filtered + paginated list, one entry, and a key-by-key
 * diff of old_values / new_values (nested values flattened with dots). Writes stay in AuditLog::record().
 */
final class AuditTrail
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{user?: string, action?: string, entity?: string, entity_id?: string, from?: string, to?: string, q?: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function search(array $f, int $page = 1, int $perPage = 50): array
    {
        $w = ['1 = 1'];
        $b = [];
        if (($f['user'] ?? '') !== '') {
            if ($f['user'] === 'system') {
                $w[] = "a.actor_type = 'system'";
            } elseif (str_starts_with((string) $f['user'], 'account')) {
                $w[] = "a.actor_type = 'account'";
            } else {
                $w[] = "a.actor_type = 'staff' AND a.actor_id = ?";
                $b[] = (int) $f['user'];
            }
        }
        if (($f['action'] ?? '') !== '') {
            $w[] = 'a.action LIKE ?';
            $b[] = str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['action']) . '%';
        }
        if (($f['entity'] ?? '') !== '') {
            $w[] = 'a.entity_type = ?';
            $b[] = $f['entity'];
        }
        if (($f['entity_id'] ?? '') !== '' && ctype_digit((string) $f['entity_id'])) {
            $w[] = 'a.entity_id = ?';
            $b[] = (int) $f['entity_id'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? ''))) {
            $w[] = 'a.created_at >= ?';
            $b[] = $f['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? ''))) {
            $w[] = 'a.created_at <= ?';
            $b[] = $f['to'] . ' 23:59:59';
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
            $w[] = '(a.reason LIKE ? OR CAST(a.new_values AS CHAR) LIKE ? OR CAST(a.old_values AS CHAR) LIKE ?)';
            array_push($b, $like, $like, $like);
        }
        $where = implode(' AND ', $w);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs a WHERE {$where}", $b);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $rows = $this->db->select(
            "SELECT a.*, s.name AS staff_name, s.role AS staff_role FROM audit_logs a LEFT JOIN staff_users s ON a.actor_type = 'staff' AND s.id = a.actor_id
             WHERE {$where} ORDER BY a.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $b,
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            "SELECT a.*, s.name AS staff_name, s.role AS staff_role, s.email AS staff_email FROM audit_logs a
             LEFT JOIN staff_users s ON a.actor_type = 'staff' AND s.id = a.actor_id WHERE a.id = ?",
            [$id],
        );
    }

    /** @return array{actions: list<string>, entities: list<string>, users: array<string, string>} filter options */
    public function options(): array
    {
        $users = ['system' => 'System (scheduled jobs)', 'account' => 'Visitors (portal)'];
        foreach ($this->db->select('SELECT id, name, role FROM staff_users ORDER BY name') as $u) {
            $users[(string) $u['id']] = $u['name'] . ' · ' . (\App\Enums\StaffRole::tryFrom((string) $u['role'])?->label() ?? '');
        }
        $prefixes = [];
        foreach ($this->db->column('SELECT DISTINCT action FROM audit_logs ORDER BY action') as $a) {
            $prefixes[(string) $a] = true;
            $prefixes[explode('.', (string) $a)[0] . '.'] = true;
        }
        ksort($prefixes);
        return [
            'actions' => array_keys($prefixes),
            'entities' => array_map('strval', $this->db->column('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type')),
            'users' => $users,
        ];
    }

    /**
     * Key-by-key comparison of old and new values.
     *
     * @param array<string, mixed> $entry audit_logs row
     * @return list<array{key: string, old: ?string, new: ?string, state: string}> state = changed | added | removed | same
     */
    public static function diff(array $entry): array
    {
        $old = self::flatten(json_decode((string) ($entry['old_values'] ?? ''), true));
        $new = self::flatten(json_decode((string) ($entry['new_values'] ?? ''), true));
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $k) {
            $o = $old[$k] ?? null;
            $n = $new[$k] ?? null;
            $state = !array_key_exists($k, $old) ? 'added' : (!array_key_exists($k, $new) ? 'removed' : ($o === $n ? 'same' : 'changed'));
            if ($entry['old_values'] === null) {
                $state = 'added';
            }
            $out[] = ['key' => (string) $k, 'old' => $o, 'new' => $n, 'state' => $state];
        }
        return $out;
    }

    /** @return array<string, ?string> */
    private static function flatten(mixed $v, string $prefix = ''): array
    {
        if (!is_array($v)) {
            return $prefix === '' ? ($v === null ? [] : ['value' => self::scalar($v)]) : [$prefix => self::scalar($v)];
        }
        if ($v === []) {
            return $prefix === '' ? [] : [$prefix => '[]'];
        }
        $list = array_is_list($v) && array_filter($v, 'is_array') === [];
        if ($list && $prefix !== '') {
            return [$prefix => implode(', ', array_map(static fn ($x) => (string) self::scalar($x), $v))];
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out += self::flatten($x, $prefix === '' ? (string) $k : $prefix . '.' . $k);
        }
        return $out;
    }

    private static function scalar(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v) => (string) $v,
            default => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
