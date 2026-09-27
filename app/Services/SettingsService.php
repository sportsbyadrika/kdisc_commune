<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Typed access to the `settings` table, loaded once per request. Helper: setting('gst_rate'). */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $stored = is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $stored],
        );
        $this->cache = null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        try {
            $rows = $this->db->select('SELECT `key`, `value`, `type` FROM settings');
        } catch (\PDOException) {
            return $this->cache; // before migrations
        }
        foreach ($rows as $row) {
            $v = $row['value'];
            $this->cache[(string) $row['key']] = match ($row['type']) {
                'int' => (int) $v,
                'float' => (float) $v,
                'bool' => in_array($v, ['1', 'true', 'yes'], true),
                'json' => json_decode((string) $v, true),
                default => $v,
            };
        }
        return $this->cache;
    }
}
