<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffRole;

final class StaffUser extends Model
{
    protected const TABLE = 'staff_users';

    /** @return array<string, mixed>|null includes password_hash — never pass to views */
    public static function findByEmail(string $email): ?array
    {
        return static::db()->first('SELECT * FROM staff_users WHERE email = ? LIMIT 1', [mb_strtolower(trim($email))]);
    }

    /** @param array<string, mixed> $user */
    public static function role(array $user): ?StaffRole
    {
        return StaffRole::tryFrom((string) ($user['role'] ?? ''));
    }

    public static function recordLogin(int $id, string $ip): void
    {
        static::update($id, ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip]);
    }
}
