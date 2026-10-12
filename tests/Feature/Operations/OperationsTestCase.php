<?php

namespace Tests\Feature\Operations;

use App\Models\Setting;
use App\Models\Tariff;
use App\Models\TariffTimeBand;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Database\Seeders\DefaultRolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class OperationsTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Car tariff: 150 per hour, 10 min grace, 200 per hour at night (22:00-06:00), 1500 daily max.
     */
    protected VehicleType $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([DefaultRolesSeeder::class, SettingsSeeder::class]);
        $this->seedVehicleTypesAndTariffs();

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

    /**
     * Vehicle types and tariffs tests need. This used to be a database seeder
     * (SampleTariffSeeder), but seeders now only set up what the app needs to run —
     * not sample configuration — so this fixture lives in the test suite instead.
     */
    private function seedVehicleTypesAndTariffs(): void
    {
        $car = VehicleType::create([
            'name' => 'Makinë', 'slug' => 'car', 'spots_used' => 1,
            'dedicated_capacity' => null, 'is_active' => true, 'sort_order' => 1,
        ]);
        $motorbike = VehicleType::create([
            'name' => 'Motor', 'slug' => 'motorbike', 'spots_used' => 0.5,
            'dedicated_capacity' => null, 'is_active' => true, 'sort_order' => 2,
        ]);
        $van = VehicleType::create([
            'name' => 'Furgon', 'slug' => 'van', 'spots_used' => 2,
            'dedicated_capacity' => 5, 'is_active' => true, 'sort_order' => 3,
        ]);

        $this->makeTariff($car, [
            'billing_unit_minutes' => 60, 'price_per_unit' => 150, 'grace_minutes' => 10, 'daily_max' => 1500,
        ], [
            ['label' => 'Natë', 'starts_at' => '22:00', 'ends_at' => '06:00', 'price_per_unit' => 200],
        ]);

        $this->makeTariff($motorbike, [
            'billing_unit_minutes' => 60, 'price_per_unit' => 50, 'grace_minutes' => 10, 'daily_max' => 500,
        ]);

        $this->makeTariff($van, [
            'billing_unit_minutes' => 60, 'price_per_unit' => 250, 'first_unit_price' => 300,
            'grace_minutes' => 5, 'daily_max' => 2500,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $timeBands
     */
    private function makeTariff(VehicleType $vehicleType, array $attributes, array $timeBands = []): Tariff
    {
        $tariff = Tariff::create(array_merge($attributes, [
            'vehicle_type_id' => $vehicleType->id,
            'name' => 'Standard',
            'rounding' => 'up',
            'is_active' => true,
        ]));

        foreach ($timeBands as $position => $band) {
            TariffTimeBand::create(array_merge($band, ['tariff_id' => $tariff->id, 'position' => $position]));
        }

        return $tariff;
    }
}
