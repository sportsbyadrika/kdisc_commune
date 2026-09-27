<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;
use App\Enums\StaffRole;
use App\Services\Auth\PasswordHasher;

/**
 * One staff login per role. DEVELOPMENT CREDENTIALS — change or deactivate before go-live.
 * Password for all: Password@123
 */
final class StaffUserSeeder extends Seeder
{
    public const PASSWORD = 'Password@123';

    public function run(): void
    {
        $centreId = (int) $this->db->scalar("SELECT id FROM centres WHERE code = 'KTR'");
        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $users = [
            ['Anjali Nair', 'reception@commune.test', '+919400000001', StaffRole::Receptionist, $centreId],
            ['Rahul Menon', 'manager@commune.test', '+919400000002', StaffRole::CentreManager, $centreId],
            ['Divya Pillai', 'finance@commune.test', '+919400000003', StaffRole::FinanceAdmin, $centreId],
            ['Suresh Kumar', 'stateadmin@commune.test', '+919400000004', StaffRole::StateAdmin, null],
        ];
        foreach ($users as [$name, $email, $mobile, $role, $centre]) {
            $this->db->execute(
                'INSERT INTO staff_users (centre_id, name, email, mobile, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), role = VALUES(role)',
                [$centre, $name, $email, $mobile, $hash, $role->value],
            );
        }
    }
}
