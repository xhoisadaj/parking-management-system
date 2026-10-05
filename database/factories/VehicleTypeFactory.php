<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\VehicleType>
 */
class VehicleTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Car', 'Motorbike', 'Van', 'Truck', 'Bicycle']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'spots_used' => 1,
            'dedicated_capacity' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function motorbike(): static
    {
        return $this->state(fn () => ['name' => 'Motorbike', 'slug' => 'motorbike', 'spots_used' => 0.5]);
    }

    public function car(): static
    {
        return $this->state(fn () => ['name' => 'Car', 'slug' => 'car', 'spots_used' => 1]);
    }

    public function van(): static
    {
        return $this->state(fn () => ['name' => 'Van', 'slug' => 'van', 'spots_used' => 2]);
    }
}
