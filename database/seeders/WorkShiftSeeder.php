<?php

namespace Database\Seeders;

use App\Models\WorkShift;
use Illuminate\Database\Seeder;

/**
 * Default work shifts. Idempotent by name, so edits made in the admin panel are kept.
 */
class WorkShiftSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Mëngjes', 'starts_at' => '06:00', 'ends_at' => '14:00'],
            ['name' => 'Pasdite', 'starts_at' => '14:00', 'ends_at' => '22:00'],
        ] as $shift) {
            WorkShift::firstOrCreate(['name' => $shift['name']], $shift + ['is_active' => true]);
        }
    }
}
