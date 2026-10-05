<?php

namespace Tests\Feature;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\VehicleType;
use App\Services\CapacityService;
use App\Services\TicketCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CapacityService $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capacity = app(CapacityService::class);
        Setting::current()->update(['total_capacity' => 2]);
    }

    private function parkVehicle(VehicleType $type): void
    {
        ParkingSession::factory()->create(['vehicle_type_id' => $type->id]);
    }

    public function test_fractional_vehicles_share_the_pool(): void
    {
        $car = VehicleType::factory()->car()->create();
        $motorbike = VehicleType::factory()->motorbike()->create();

        $this->assertSame(2, $this->capacity->freeVehiclesFor($car));
        $this->assertSame(4, $this->capacity->freeVehiclesFor($motorbike));

        $this->parkVehicle($car);

        $this->assertSame(1, $this->capacity->freeVehiclesFor($car));
        $this->assertSame(2, $this->capacity->freeVehiclesFor($motorbike));
    }

    public function test_full_pool_blocks_admission(): void
    {
        $car = VehicleType::factory()->car()->create();

        $this->parkVehicle($car);
        $this->parkVehicle($car);

        $this->assertSame(0, $this->capacity->freeVehiclesFor($car));
        $this->assertFalse($this->capacity->canAdmit($car));
    }

    public function test_dedicated_cap_limits_a_type_even_when_the_pool_has_room(): void
    {
        Setting::current()->update(['total_capacity' => 10]);
        $van = VehicleType::factory()->van()->create(['dedicated_capacity' => 1]);

        $this->assertSame(1, $this->capacity->freeVehiclesFor($van));

        $this->parkVehicle($van);

        $this->assertSame(0, $this->capacity->freeVehiclesFor($van));
    }

    public function test_inactive_sessions_do_not_count_towards_occupancy(): void
    {
        $car = VehicleType::factory()->car()->create();

        ParkingSession::factory()->paid()->create(['vehicle_type_id' => $car->id]);

        $this->assertSame(0.0, $this->capacity->occupiedSpots());
        $this->assertSame(2, $this->capacity->freeVehiclesFor($car));
    }

    public function test_ticket_codes_are_unique_and_clean(): void
    {
        $generator = new TicketCodeGenerator;

        $codes = array_map(fn () => $generator->generate(), range(1, 50));

        $this->assertCount(50, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
        }
    }
}
