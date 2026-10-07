<?php

namespace Tests\Feature\Operations;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\Tariff;
use App\Models\VehicleType;
use App\Services\TicketIssuer;
use Livewire\Livewire;
use App\Livewire\Operator\EntryScreen;
use Illuminate\Auth\Access\AuthorizationException;

class TicketIssuerTest extends OperationsTestCase
{
    private function issuer(): TicketIssuer
    {
        return app(TicketIssuer::class);
    }

    public function test_issues_a_ticket_with_a_frozen_tariff_snapshot(): void
    {
        $session = $this->issuer()->issue($this->car, 'aa-123 bb', $this->userWith('Operator'));

        $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->status);
        $this->assertSame('AA123BB', $session->plate, 'Plate is normalised');
        $this->assertSame(8, strlen($session->ticket_code));
        $this->assertSame(150.0, (float) $session->tariff_snapshot['price_per_unit']);
        $this->assertSame('Europe/Tirane', $session->tariff_snapshot['timezone']);
    }

    public function test_plate_is_optional(): void
    {
        $session = $this->issuer()->issue($this->car, '  ', $this->userWith('Operator'));

        $this->assertNull($session->plate);
    }

    public function test_capacity_is_never_exceeded_by_sequential_entries(): void
    {
        Setting::current()->update(['total_capacity' => 3]);
        $operator = $this->userWith('Operator');

        for ($i = 0; $i < 3; $i++) {
            $this->issuer()->issue($this->car, null, $operator);
        }

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Nuk ka vende të lira për Makinë.');

        $this->issuer()->issue($this->car, null, $operator);
    }

    public function test_motorbikes_use_half_a_spot_so_two_fit_in_one_spot(): void
    {
        Setting::current()->update(['total_capacity' => 1]);
        $motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();
        $operator = $this->userWith('Operator');

        $this->issuer()->issue($motorbike, null, $operator);
        $this->issuer()->issue($motorbike, null, $operator);

        $this->expectException(ParkingException::class);
        $this->issuer()->issue($motorbike, null, $operator);
    }

    public function test_dedicated_cap_blocks_a_type_even_with_room_in_the_pool(): void
    {
        $van = VehicleType::where('slug', 'van')->firstOrFail();
        $van->update(['dedicated_capacity' => 1]);
        $operator = $this->userWith('Operator');

        $this->issuer()->issue($van, null, $operator);

        $this->expectException(ParkingException::class);
        $this->issuer()->issue($van, null, $operator);
    }

    public function test_inactive_vehicle_type_is_refused(): void
    {
        $this->car->update(['is_active' => false]);

        $this->expectException(ParkingException::class);
        $this->issuer()->issue($this->car, null, $this->userWith('Operator'));
    }

    public function test_no_valid_tariff_blocks_entry(): void
    {
        Tariff::query()->update(['is_active' => false]);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Nuk ka tarifë aktive për Makinë.');
        $this->issuer()->issue($this->car, null, $this->userWith('Operator'));
    }

    public function test_entry_screen_shows_the_ticket_and_dispatches_the_print_event(): void
    {
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(EntryScreen::class)
            ->set('plate', 'AB-1')
            ->call('issue', $this->car->id)
            ->assertSet('error', null)
            ->assertSet('issued.vehicle', 'Makinë')
            ->assertDispatched('print-ticket');

        $this->assertDatabaseHas('parking_sessions', ['plate' => 'AB1', 'entry_user_id' => $operator->id]);
    }

    public function test_entry_screen_blocks_when_full_and_says_why(): void
    {
        Setting::current()->update(['total_capacity' => 1]);
        $operator = $this->userWith('Operator');
        $this->issuer()->issue($this->car, null, $operator);

        Livewire::actingAs($operator)
            ->test(EntryScreen::class)
            ->call('issue', $this->car->id)
            ->assertSet('error', 'Nuk ka vende të lira për Makinë.')
            ->assertNotDispatched('print-ticket');

        $this->assertSame(1, ParkingSession::count());
    }

    public function test_user_without_issue_permission_cannot_open_the_entry_screen(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())->get('/operator/entry')->assertForbidden();
    }
}
