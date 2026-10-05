<?php

namespace Tests\Feature\Operations;

use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Database\Seeders\DefaultRolesSeeder;
use Database\Seeders\SampleTariffSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class OperationsTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Sample car tariff: 150 per hour, 10 min grace, 200 per hour at night (22:00-06:00), 1500 daily max.
     */
    protected VehicleType $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([DefaultRolesSeeder::class, SettingsSeeder::class, SampleTariffSeeder::class]);

        Setting::current()->update(['total_capacity' => 10, 'lost_ticket_fee' => 3000]);

        $this->car = VehicleType::where('slug', 'car')->firstOrFail();

        // Fixed local time so duration and price are reproducible.
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00', 'Europe/Tirane'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function at(string $local): Carbon
    {
        return Carbon::parse($local, 'Europe/Tirane');
    }
}
