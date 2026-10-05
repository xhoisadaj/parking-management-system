<?php

namespace Database\Seeders;

use App\Models\Tariff;
use App\Models\TariffTimeBand;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

/**
 * Sample vehicle types and tariffs. Idempotent by slug and tariff name.
 */
class SampleTariffSeeder extends Seeder
{
    public function run(): void
    {
        $car = VehicleType::updateOrCreate(['slug' => 'car'], [
            'name' => 'Car', 'spots_used' => 1, 'dedicated_capacity' => null, 'is_active' => true, 'sort_order' => 1,
        ]);

        $motorbike = VehicleType::updateOrCreate(['slug' => 'motorbike'], [
            'name' => 'Motorbike', 'spots_used' => 0.5, 'dedicated_capacity' => null, 'is_active' => true, 'sort_order' => 2,
        ]);

        $van = VehicleType::updateOrCreate(['slug' => 'van'], [
            'name' => 'Van', 'spots_used' => 2, 'dedicated_capacity' => 5, 'is_active' => true, 'sort_order' => 3,
        ]);

        $this->tariff($car, 'Standard', [
            'billing_unit_minutes' => 60,
            'price_per_unit' => 150,
            'grace_minutes' => 10,
            'daily_max' => 1500,
        ], [
            ['label' => 'Night', 'starts_at' => '22:00', 'ends_at' => '06:00', 'price_per_unit' => 200],
        ]);

        $this->tariff($motorbike, 'Standard', [
            'billing_unit_minutes' => 60,
            'price_per_unit' => 50,
            'grace_minutes' => 10,
            'daily_max' => 500,
        ]);

        $this->tariff($van, 'Standard', [
            'billing_unit_minutes' => 60,
            'price_per_unit' => 250,
            'first_unit_price' => 300,
            'grace_minutes' => 5,
            'daily_max' => 2500,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $timeBands
     */
    private function tariff(VehicleType $vehicleType, string $name, array $attributes, array $timeBands = []): void
    {
        $tariff = Tariff::updateOrCreate(
            ['vehicle_type_id' => $vehicleType->id, 'name' => $name],
            array_merge($attributes, ['rounding' => 'up', 'is_active' => true]),
        );

        $tariff->timeBands()->delete();

        foreach ($timeBands as $position => $band) {
            TariffTimeBand::create(array_merge($band, ['tariff_id' => $tariff->id, 'position' => $position]));
        }
    }
}
