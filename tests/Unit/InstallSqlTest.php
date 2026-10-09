<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * deploy/sql/install.sql is the phpMyAdmin first-install file for shared hosting. It must contain every migration
 * (schema + bookkeeping row) — rebuild with bin/build-install-sql.sh after adding a migration or changing a seeder.
 */
final class InstallSqlTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testEveryMigrationIsInTheInstallFile(): void
    {
        $sql = (string) file_get_contents(self::ROOT . '/deploy/sql/install.sql');
        self::assertNotSame('', $sql, 'deploy/sql/install.sql is missing: run bin/build-install-sql.sh');

        foreach (glob(self::ROOT . '/database/migrations/*.sql') ?: [] as $file) {
            $name = basename($file);
            self::assertStringContainsString("-- ── {$name}", $sql, "{$name} schema missing: run bin/build-install-sql.sh");
            self::assertStringContainsString("'" . pathinfo($name, PATHINFO_FILENAME), $sql, "{$name} not recorded in migrations: run bin/build-install-sql.sh");
        }
    }

    public function testInstallFileHasNoStaffLoginsOrDatabaseSwitch(): void
    {
        $sql = (string) file_get_contents(self::ROOT . '/deploy/sql/install.sql');

        self::assertDoesNotMatchRegularExpression('/^(INSERT|REPLACE) INTO `staff_users`/m', $sql, 'no demo staff logins in production SQL');
        self::assertDoesNotMatchRegularExpression('/^(CREATE DATABASE|USE )/mi', $sql, 'phpMyAdmin imports into the selected database');
        self::assertStringContainsString('REPLACE INTO `seats`', $sql);
    }
}
