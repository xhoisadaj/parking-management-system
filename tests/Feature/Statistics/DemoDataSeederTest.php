<?php

namespace Tests\Feature\Statistics;

use App\Models\ParkingSession;
use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DefaultRolesSeeder;
use Database\Seeders\SampleTariffSeeder;
use Database\Seeders\SettingsSeeder;
use Tests\Feature\Operations\OperationsTestCase;

class DemoDataSeederTest extends OperationsTestCase
{
    public function test_demo_data_covers_every_status_and_several_weeks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00', 'Europe/Tirane'));

        $this->seed(DemoDataSeeder::class);

        $statuses = ParkingSession::query()->distinct()->pluck('status')->all();
        sort($statuses);

        $this->assertSame(['active', 'lost', 'paid', 'void'], $statuses);
        $this->assertGreaterThan(300, ParkingSession::count());

        $oldest = ParkingSession::query()->min('entered_at');
        $this->assertGreaterThanOrEqual('2026-05-14', substr((string) $oldest, 0, 10));

        $this->assertSame(0, ParkingSession::query()->where('entered_at', '>', now())->count(), 'No demo entries in the future');
    }

    public function test_demo_adjustments_stay_within_the_manager_discount_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00', 'Europe/Tirane'));

        $this->seed(DemoDataSeeder::class);

        $adjusted = ParkingSession::query()->whereNotNull('adjusted_by')->get();

        $this->assertNotEmpty($adjusted);
        foreach ($adjusted as $session) {
            $this->assertLessThanOrEqual(0.0 + (float) $session->calculated_price, (float) $session->final_price);
            $discount = ((float) $session->calculated_price - (float) $session->final_price) / (float) $session->calculated_price * 100;
            $this->assertLessThanOrEqual(20.0001, $discount);
        }
    }
}
