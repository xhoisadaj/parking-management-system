<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first Admin account. Email and password come from the environment
 * so nothing sensitive is hardcoded. Defaults are for local development only.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@parking.test')],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => env('ADMIN_PASSWORD', 'password'),
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        $admin->syncRoles(['Admin']);
    }
}
