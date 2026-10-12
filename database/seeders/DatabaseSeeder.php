<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with what it needs to be usable: roles and permissions,
     * the first admin login, settings, and the default work shifts.
     *
     * Nothing here creates vehicle types, tariffs, or tickets — the admin sets those up in the
     * panel after the first login.
     */
    public function run(): void
    {
        $this->call([
            DefaultRolesSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            WorkShiftSeeder::class,
        ]);
    }
}
