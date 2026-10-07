<?php

namespace Database\Seeders;

use App\Models\OpeningHour;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::current()->update([
            'parking_name' => 'Parkimi Qendër',
            'address' => 'Rruga Dibrës 1, Tiranë, Shqipëri',
            'total_capacity' => 60,
            'currency' => 'ALL',
            'lost_ticket_fee' => 3000,
            'ticket_header' => 'Mirë se erdhët në Parkimin Qendër',
            'ticket_footer' => 'Ruajeni këtë biletë. Biletat e humbura paguhen me tarifën e biletës së humbur.',
            'ticket_paper_width_mm' => 80,
            'timezone' => 'Europe/Tirane',
        ]);

        // 0 = Sunday ... 6 = Saturday. Weekends close later, Sunday closed.
        $hours = [
            0 => ['is_closed' => true, 'opens_at' => null, 'closes_at' => null],
            1 => ['is_closed' => false, 'opens_at' => '07:00', 'closes_at' => '22:00'],
            2 => ['is_closed' => false, 'opens_at' => '07:00', 'closes_at' => '22:00'],
            3 => ['is_closed' => false, 'opens_at' => '07:00', 'closes_at' => '22:00'],
            4 => ['is_closed' => false, 'opens_at' => '07:00', 'closes_at' => '22:00'],
            5 => ['is_closed' => false, 'opens_at' => '07:00', 'closes_at' => '23:00'],
            6 => ['is_closed' => false, 'opens_at' => '08:00', 'closes_at' => '23:00'],
        ];

        foreach ($hours as $weekday => $attributes) {
            OpeningHour::updateOrCreate(['weekday' => $weekday], $attributes);
        }
    }
}
