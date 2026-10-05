<?php

namespace Database\Factories;

use App\Models\ParkingSession;
use App\Models\Tariff;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParkingSession>
 */
class ParkingSessionFactory extends Factory
{
    public function definition(): array
    {
        // Closures keep these lazy so callers can override vehicle_type_id without creating an extra type.
        return [
            'ticket_code' => strtoupper(Str::random(10)),
            'vehicle_type_id' => fn () => VehicleType::factory()->car()->create()->id,
            'plate' => strtoupper(fake()->bothify('??-###-??')),
            'entered_at' => now()->subMinutes(fake()->numberBetween(5, 600)),
            'exited_at' => null,
            'duration_minutes' => null,
            'calculated_price' => null,
            'final_price' => null,
            'adjustment_reason' => null,
            'adjusted_by' => null,
            'entry_user_id' => User::factory(),
            'exit_user_id' => null,
            'status' => ParkingSession::STATUS_ACTIVE,
            'tariff_snapshot' => function (array $attributes) {
                $tariff = Tariff::factory()->create(['vehicle_type_id' => $attributes['vehicle_type_id']]);

                return $tariff->toSnapshot();
            },
        ];
    }

    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            $exited = \Illuminate\Support\Carbon::parse($attributes['entered_at'])->addMinutes(fake()->numberBetween(20, 480));
            $minutes = (int) \Illuminate\Support\Carbon::parse($attributes['entered_at'])->diffInMinutes($exited, true);
            $price = (float) fake()->randomElement([0, 100, 200, 300, 400]);

            return [
                'exited_at' => $exited,
                'duration_minutes' => $minutes,
                'calculated_price' => $price,
                'final_price' => $price,
                'exit_user_id' => User::factory(),
                'status' => ParkingSession::STATUS_PAID,
            ];
        });
    }
}
