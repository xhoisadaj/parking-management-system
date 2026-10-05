<?php

namespace Database\Factories;

use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Tariff>
 */
class TariffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vehicle_type_id' => VehicleType::factory(),
            'name' => 'Standard',
            'billing_unit_minutes' => 60,
            'price_per_unit' => 100,
            'first_unit_price' => null,
            'grace_minutes' => 0,
            'daily_max' => null,
            'rounding' => 'up',
            'active_from' => null,
            'active_to' => null,
            'is_active' => true,
        ];
    }
}
