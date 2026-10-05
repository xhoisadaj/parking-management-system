<?php

namespace Tests\Feature\Operations;

use App\Exceptions\ParkingException;
use App\Livewire\Operator\LostTicketScreen;
use App\Models\AuditLog;
use App\Models\ParkingSession;
use App\Models\User;
use App\Services\CapacityService;
use App\Services\LostTicketService;
use App\Services\TicketIssuer;
use App\Services\VoidService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

class LostVoidTest extends OperationsTestCase
{
    private function enter(): ParkingSession
    {
        return app(TicketIssuer::class)->issue($this->car, 'LOST1', $this->userWith('Operator'), $this->at('2026-06-01 10:00'));
    }

    public function test_lost_ticket_is_charged_the_lost_fee_and_not_counted_as_an_adjustment(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        $closed = app(LostTicketService::class)->markLost($session, $operator, $this->at('2026-06-01 12:00'));

        $this->assertSame(ParkingSession::STATUS_LOST, $closed->status);
        $this->assertSame(3000.0, (float) $closed->final_price);
        $this->assertSame(300.0, (float) $closed->calculated_price, 'Stay price kept for reference');
        $this->assertNull($closed->adjusted_by);
        $this->assertSame($operator->id, $closed->exit_user_id);
        $this->assertSame(1, AuditLog::where('event', 'ticket.lost')->count());
    }

    public function test_user_without_checkout_cannot_close_a_lost_ticket(): void
    {
        $session = $this->enter();

        $this->expectException(AuthorizationException::class);

        app(LostTicketService::class)->markLost($session, User::factory()->create(), $this->at('2026-06-01 12:00'));
    }

    public function test_lost_ticket_screen_lists_cars_and_applies_the_fee(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(LostTicketScreen::class)
            ->assertSee('LOST1')
            ->set('search', 'lost1')
            ->call('select', $session->id)
            ->assertSee('Charge lost-ticket fee')
            ->call('markLost')
            ->assertSet('error', null)
            ->assertDispatched('print-ticket');

        $this->assertSame(ParkingSession::STATUS_LOST, $session->fresh()->status);
    }

    public function test_void_frees_the_spot_and_is_audited_with_a_reason(): void
    {

        $session = $this->enter();
        $manager = $this->userWith('Manager');
        $capacity = app(CapacityService::class);
        $before = $capacity->occupiedSpots();

        app(VoidService::class)->void($session, $manager, 'Wrong vehicle type');

        $this->assertSame(ParkingSession::STATUS_VOID, $session->fresh()->status);
        $this->assertSame($before - 1.0, $capacity->occupiedSpots());

        $entry = AuditLog::where('event', 'ticket.voided')->firstOrFail();
        $this->assertSame('Wrong vehicle type', $entry->reason);
        $this->assertSame($manager->id, $entry->user_id);
    }

    public function test_void_requires_the_void_permission(): void
    {
        $session = $this->enter();

        $this->expectException(AuthorizationException::class);

        app(VoidService::class)->void($session, $this->userWith('Operator'), 'because');
    }

    public function test_void_requires_a_reason(): void
    {
        $session = $this->enter();

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Enter a reason');

        app(VoidService::class)->void($session, $this->userWith('Manager'), '  ');
    }

    public function test_only_active_tickets_can_be_voided(): void
    {
        $session = $this->enter();
        $session->update(['status' => ParkingSession::STATUS_PAID]);

        $this->expectException(ParkingException::class);

        app(VoidService::class)->void($session, $this->userWith('Manager'), 'late');
    }
}
