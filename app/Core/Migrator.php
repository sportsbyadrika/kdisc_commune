<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Runs database/migrations in filename order and records them in `migrations`.
 *
 * File types:
 *   *.sql  plain SQL; statements separated by ";" at end of line. Lines starting with "--" are comments.
 *          (Do not put ";" at a line end inside string literals.)
 *   *.php  must `return function (App\Core\Database $db): void { ... };` (data migrations)
 *
 * Name files YYYY_MM_DD_HHMMSS_description.sql — never edit a migration that has run in
 * production; add a new one (ALTER TABLE ...) instead.
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    public function __construct(private readonly Database $db, private readonly string $path, ?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    public function migrate(): int
    {
        $this->ensureTable();
        $ran = $this->ranMigrations();
        $pending = array_filter($this->files(), static fn (string $f) => !in_array(basename($f), $ran, true));
        if ($pending === []) {
            ($this->output)('Nothing to migrate.');
            return 0;
        }
        $batch = (int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;
        foreach ($pending as $file) {
            $name = basename($file);
            $start = microtime(true);
            $this->runFile($file);
            $this->db->insert('migrations', ['migration' => $name, 'batch' => $batch]);
            ($this->output)(sprintf('Migrated  %s (%.0f ms)', $name, (microtime(true) - $start) * 1000));
        }
        return count($pending);
    }

    /** Drop every table in the database, then migrate from scratch. */
    public function fresh(): int
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $tables = $this->db->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
            foreach ($tables as $table) {
                $this->db->execute('DROP TABLE IF EXISTS ' . $this->db->quoteIdentifier((string) $table));
            }
            ($this->output)(sprintf('Dropped %d tables.', count($tables)));
        } finally {
            $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $this->migrate();
    }

    /** @return list<array{migration: string, ran: bool, batch: int|null}> */
    public function status(): array
    {
        $this->ensureTable();
        $rows = [];
        foreach ($this->db->select('SELECT migration, batch FROM migrations') as $r) {
            $rows[(string) $r['migration']] = (int) $r['batch'];
        }
        $out = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            $out[] = ['migration' => $name, 'ran' => isset($rows[$name]), 'batch' => $rows[$name] ?? null];
        }
        return $out;
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = array_merge(glob($this->path . '/*.sql') ?: [], glob($this->path . '/*.php') ?: []);
        usort($files, static fn (string $a, string $b) => strcmp(basename($a), basename($b)));
        return $files;
    }

    /** @return list<string> */
    public static function splitSql(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $l) => !str_starts_with(ltrim($l), '--'),
        );
        $statements = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $statements), static fn (string $s) => $s !== ''));
    }

    private function runFile(string $file): void
    {
        if (str_ends_with($file, '.php')) {
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("PHP migration {$file} must return a callable.");
            }
            $migration($this->db);
            return;
        }
        foreach (self::splitSql((string) file_get_contents($file)) as $statement) {
            $this->db->pdo()->exec($statement);
        }
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL,
            batch INT UNSIGNED NOT NULL,
            ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        return array_map('strval', $this->db->column('SELECT migration FROM migrations ORDER BY id'));
    }
}
