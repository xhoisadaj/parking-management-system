<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DefaultRolesSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            SampleTariffSeeder::class,
            WorkShiftSeeder::class,
            DemoDataSeeder::class,
        ]);
    }
}
