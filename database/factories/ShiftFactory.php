<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'opened_at' => now()->subHours(8),
            'closed_at' => null,
            'tickets_issued' => 0,
            'checkouts' => 0,
            'cash_collected' => 0,
            'reconciled_by' => null,
            'reconciled_at' => null,
            'reconciliation_note' => null,
        ];
    }
}
