<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Database;

/**
 * Minimal table gateway. Rows are plain associative arrays (no magic objects).
 * Subclasses set TABLE and add intention-revealing query methods:
 *
 *   final class Facility extends Model {
 *       protected const TABLE = 'facilities';
 *       public static function active(): array { return static::where(['is_active' => 1], 'sort_order'); }
 *   }
 *
 * For anything non-trivial write SQL with db()->select(...) in a Service.
 */
abstract class Model
{
    protected const TABLE = '';
    protected const PRIMARY_KEY = 'id';

    protected static function db(): Database
    {
        /** @var Database */
        return App::container()->get(Database::class);
    }

    public static function table(): string
    {
        return static::TABLE;
    }

    /** @return array<string, mixed>|null */
    public static function find(int|string $id): ?array
    {
        $db = static::db();
        return $db->first(
            sprintf('SELECT * FROM %s WHERE %s = ? LIMIT 1', $db->quoteIdentifier(static::TABLE), $db->quoteIdentifier(static::PRIMARY_KEY)),
            [$id],
        );
    }

    /** @return array<string, mixed> */
    public static function findOrFail(int|string $id): array
    {
        return static::find($id) ?? throw new \App\Core\Exceptions\NotFoundException();
    }

    /**
     * @param array<string, mixed> $conditions equality conditions (AND)
     * @return list<array<string, mixed>>
     */
    public static function where(array $conditions = [], ?string $orderBy = null, ?int $limit = null): array
    {
        $db = static::db();
        $sql = 'SELECT * FROM ' . $db->quoteIdentifier(static::TABLE);
        $bindings = [];
        if ($conditions !== []) {
            $parts = [];
            foreach ($conditions as $col => $value) {
                if ($value === null) {
                    $parts[] = $db->quoteIdentifier($col) . ' IS NULL';
                } else {
                    $parts[] = $db->quoteIdentifier($col) . ' = ?';
                    $bindings[] = $value;
                }
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }
        if ($orderBy !== null) {
            [$col, $dir] = array_pad(explode(' ', trim($orderBy), 2), 2, 'ASC');
            $sql .= ' ORDER BY ' . $db->quoteIdentifier($col) . (strtoupper($dir) === 'DESC' ? ' DESC' : ' ASC');
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }
        return $db->select($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>|null
     */
    public static function firstWhere(array $conditions): ?array
    {
        return static::where($conditions, null, 1)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public static function all(?string $orderBy = null): array
    {
        return static::where([], $orderBy);
    }

    /** @param array<string, mixed> $conditions */
    public static function count(array $conditions = []): int
    {
        $db = static::db();
        $sql = 'SELECT COUNT(*) FROM ' . $db->quoteIdentifier(static::TABLE);
        $bindings = [];
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', array_map(static fn (string $c) => $db->quoteIdentifier($c) . ' = ?', array_keys($conditions)));
            $bindings = array_values($conditions);
        }
        return (int) $db->scalar($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $data
     * @return int new id
     */
    public static function create(array $data): int
    {
        return static::db()->insert(static::TABLE, $data);
    }

    /** @param array<string, mixed> $data */
    public static function update(int|string $id, array $data): int
    {
        return static::db()->update(static::TABLE, $data, [static::PRIMARY_KEY => $id]);
    }
}
