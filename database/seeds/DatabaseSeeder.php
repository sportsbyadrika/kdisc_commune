<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;

/**
 * Seeds a fresh database with the Kottarakara centre, its layout, prices,
 * facilities, settings and one staff login per role.
 *   php bin/console migrate:fresh --seed
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(
            SettingsSeeder::class,
            CentreSeeder::class,
            SeatCategorySeeder::class,
            StaffUserSeeder::class,
            LayoutSeeder::class,
            FacilitySeeder::class,
        );
    }
}
