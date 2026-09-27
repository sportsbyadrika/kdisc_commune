<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. ALWAYS use bound parameters:
 *
 *   $db->select('SELECT * FROM seats WHERE zone_id = ?', [$zoneId]);
 *   $db->first('SELECT * FROM staff_users WHERE email = :email', ['email' => $email]);
 *   $db->insert('audit_logs', ['action' => 'x', ...]);        // returns new id
 *   $db->update('seats', ['status' => 'blocked'], ['id' => 5]); // returns affected rows
 *   $db->transaction(function (Database $db) { ... });          // commit / rollback
 */
final class Database
{
    private static ?Database $instance = null;

    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            /** @var array<string, mixed> $cfg */
            $cfg = App::config('database.connections.' . App::config('database.default', 'mysql'), []);
            self::$instance = new self($cfg);
        }
        return self::$instance;
    }

    public static function setInstance(?self $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'] ?? '127.0.0.1',
                (int) ($c['port'] ?? 3306),
                $c['database'] ?? '',
                $c['charset'] ?? 'utf8mb4',
            );
            if (!empty($c['socket'])) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['socket'], $c['database'] ?? '', $c['charset'] ?? 'utf8mb4');
            }
            $this->pdo = new PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $collation = $c['collation'] ?? 'utf8mb4_unicode_ci';
            $this->pdo->exec("SET NAMES utf8mb4 COLLATE {$collation}");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $tz = (string) ($c['timezone'] ?? '+05:30');
            $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($tz));
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $bindings */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $value = (int) $value;
            }
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * First column of the first row.
     * @param array<int|string, mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<mixed>
     */
    public function column(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Execute a write statement and return affected rows.
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $cols)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)),
        );
        $this->statement($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where equality conditions joined with AND
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $bindings = [];
        foreach ($data as $col => $value) {
            $set[] = $this->quoteIdentifier($col) . ' = :set_' . $col;
            $bindings['set_' . $col] = $value;
        }
        [$whereSql, $whereBindings] = $this->buildWhere($where);
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), implode(', ', $set), $whereSql);
        return $this->execute($sql, $bindings + $whereBindings);
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        [$whereSql, $bindings] = $this->buildWhere($where);
        return $this->execute(sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $whereSql), $bindings);
    }

    /**
     * Run a callback inside a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBackIfActive();
            throw $e;
        }
    }

    /** A DDL statement or failed commit may already have ended the transaction. */
    private function rollBackIfActive(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier [{$name}].");
        }
        return implode('.', array_map(static fn (string $p): string => '`' . $p . '`', explode('.', $name)));
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $where): array
    {
        if ($where === []) {
            throw new \InvalidArgumentException('Refusing to run UPDATE/DELETE without a WHERE clause.');
        }
        $parts = [];
        $bindings = [];
        foreach ($where as $col => $value) {
            if ($value === null) {
                $parts[] = $this->quoteIdentifier($col) . ' IS NULL';
                continue;
            }
            $parts[] = $this->quoteIdentifier($col) . ' = :w_' . $col;
            $bindings['w_' . $col] = $value;
        }
        return [implode(' AND ', $parts), $bindings];
    }
}
