<?php

namespace Database\Factories;

use App\Models\Tariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TariffTimeBand>
 */
class TariffTimeBandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tariff_id' => Tariff::factory(),
            'label' => 'Night',
            'starts_at' => '22:00',
            'ends_at' => '06:00',
            'price_per_unit' => 150,
            'position' => 0,
        ];
    }
}
