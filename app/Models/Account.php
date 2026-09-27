<?php

declare(strict_types=1);

namespace App\Models;

/** Visitor portal login (accounts). Rows include password_hash — never pass them to views. */
final class Account extends Model
{
    protected const TABLE = 'accounts';

    /** @return array<string, mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return static::db()->first('SELECT * FROM accounts WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
    }

    public static function recordLogin(int $id, string $ip): void
    {
        static::update($id, ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip]);
    }
}
