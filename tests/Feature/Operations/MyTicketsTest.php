<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\MyTicketsScreen;
use App\Models\ParkingSession;
use App\Services\ShiftService;
use App\Services\TicketIssuer;
use Livewire\Livewire;

class MyTicketsTest extends OperationsTestCase
{
    private function grant($user)
    {
        $user->givePermissionTo('manage_own_tickets');

        return $user->fresh();
    }

    public function test_the_route_is_forbidden_without_the_permission(): void
    {
        $operator = $this->userWith('Operator');

        $this->actingAs($operator)->get('/operator/my-tickets')->assertForbidden();
    }

    public function test_the_route_works_once_granted(): void
    {
        $operator = $this->grant($this->userWith('Operator'));

        $this->actingAs($operator)->get('/operator/my-tickets')->assertOk();
    }

    public function test_the_nav_tab_only_shows_up_once_granted(): void
    {
        $withoutIt = $this->userWith('Operator');
        $withIt = $this->grant($this->userWith('Operator'));

        $this->actingAs($withoutIt)->get('/operator/shift')->assertOk()->assertDontSee('Biletat e mia');
        $this->actingAs($withIt)->get('/operator/shift')->assertOk()->assertSee('Biletat e mia');
    }

    public function test_it_only_lists_tickets_this_operator_issued(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $other = $this->userWith('Operator');

        $this->travelTo($this->at('2026-06-01 10:00'));
        $mine = app(TicketIssuer::class)->issue($this->car, 'MINE1', $operator, $this->at('2026-06-01 10:00'));
        app(TicketIssuer::class)->issue($this->car, 'THEIRS', $other, $this->at('2026-06-01 10:05'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->assertSee('MINE1')
            ->assertDontSee('THEIRS');
    }

    public function test_each_card_carries_the_ticket_code_for_the_click_to_copy_handler(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->assertSee('data-copy-code="'.$ticket->ticket_code.'"', false);
    }

    public function test_the_today_filter_excludes_a_ticket_from_a_different_day(): void
    {
        $operator = $this->grant($this->userWith('Operator'));

        app(TicketIssuer::class)->issue($this->car, 'OLD1', $operator, $this->at('2026-05-30 10:00'));
        $this->travelTo($this->at('2026-06-01 09:00'));
        $today = app(TicketIssuer::class)->issue($this->car, 'TODAY1', $operator, $this->at('2026-06-01 09:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->set('period', 'today')
            ->assertSee('TODAY1')
            ->assertDontSee('OLD1');
    }

    public function test_the_week_filter_includes_a_ticket_from_a_few_days_ago(): void
    {
        $operator = $this->grant($this->userWith('Operator'));

        app(TicketIssuer::class)->issue($this->car, 'DAY3', $operator, $this->at('2026-05-29 10:00'));
        $this->travelTo($this->at('2026-06-01 09:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->set('period', 'week')
            ->assertSee('DAY3');
    }

    public function test_an_active_ticket_can_be_edited(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $motorbike = \App\Models\VehicleType::where('slug', 'motorbike')->firstOrFail();

        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->set('plate', 'NEWPLATE')
            ->set('vehicleTypeId', (string) $motorbike->id)
            ->call('save')
            ->assertSet('error', null);

        $ticket->refresh();
        $this->assertSame('NEWPLATE', $ticket->plate);
        $this->assertSame($motorbike->id, $ticket->vehicle_type_id);
    }

    public function test_a_closed_ticket_cannot_be_selected(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));
        app(\App\Services\CheckoutService::class)->complete($ticket, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->assertSet('selectedId', null)
            ->assertSet('error', fn (?string $e) => $e !== null);
    }

    public function test_someone_elses_active_ticket_cannot_be_selected(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $other = $this->userWith('Operator');
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $other, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->assertSet('selectedId', null);
    }

    public function test_a_price_override_without_adjust_own_ticket_price_is_refused(): void
    {
        $operator = $this->grant($this->userWith('Operator')); // manage_own_tickets but not adjust_own_ticket_price
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->set('priceOverride', '999')
            ->set('priceOverrideReason', 'nice try')
            ->call('save')
            ->assertSet('error', 'Nuk keni leje të ndryshoni çmimin.');

        $this->assertNull($ticket->fresh()->final_price);
    }

    public function test_a_price_override_with_adjust_own_ticket_price_works(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $operator->givePermissionTo('adjust_own_ticket_price');
        $operator = $operator->fresh();
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->set('priceOverride', '777')
            ->set('priceOverrideReason', 'VIP rate')
            ->call('save')
            ->assertSet('error', null);

        $this->assertSame(777.0, (float) $ticket->fresh()->final_price);
    }

    public function test_the_price_fields_are_hidden_without_adjust_own_ticket_price(): void
    {
        $operator = $this->grant($this->userWith('Operator'));
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->assertDontSee('Çmimi i personalizuar');
    }

    public function test_holding_only_the_checkout_adjust_price_permission_does_not_unlock_price_here(): void
    {
        // adjust_price and adjust_own_ticket_price are deliberately separate: one is for
        // discounts at checkout, the other for correcting a price on this page. Holding the
        // checkout one alone must not also unlock this page's price field.
        $operator = $this->grant($this->userWith('Operator'));
        $operator->givePermissionTo('adjust_price');
        $operator = $operator->fresh();
        $this->travelTo($this->at('2026-06-01 10:00'));
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->call('select', $ticket->id)
            ->assertDontSee('Çmimi i personalizuar')
            ->set('priceOverride', '999')
            ->set('priceOverrideReason', 'nice try')
            ->call('save')
            ->assertSet('error', 'Nuk keni leje të ndryshoni çmimin.');

        $this->assertNull($ticket->fresh()->final_price);
    }

    public function test_shift_period_uses_the_current_shifts_opening_time(): void
    {
        $operator = $this->grant($this->userWith('Operator'));

        $before = app(TicketIssuer::class)->issue($this->car, 'BEFORE', $operator, $this->at('2026-06-01 08:00'));
        app(ShiftService::class)->close($operator, $this->at('2026-06-01 09:00'), 0.0);
        app(ShiftService::class)->ensureOpen($operator, $this->at('2026-06-01 10:00'), 0.0);
        $this->travelTo($this->at('2026-06-01 10:30'));
        $during = app(TicketIssuer::class)->issue($this->car, 'DURING', $operator, $this->at('2026-06-01 10:30'));

        Livewire::actingAs($operator)
            ->test(MyTicketsScreen::class)
            ->set('period', 'shift')
            ->assertSee('DURING')
            ->assertDontSee('BEFORE');

        $this->assertNotNull($before);
        $this->assertNotNull($during);
    }
}
