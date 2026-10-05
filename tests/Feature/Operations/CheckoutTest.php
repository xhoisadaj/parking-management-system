<?php

namespace Tests\Feature\Operations;

use App\Exceptions\ParkingException;
use App\Livewire\Operator\CheckoutScreen;
use App\Models\AuditLog;
use App\Models\ParkingSession;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\TicketIssuer;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

class CheckoutTest extends OperationsTestCase
{
    private function enter(string $at = '2026-06-01 10:00'): ParkingSession
    {
        $this->travelTo($this->at($at));

        return app(TicketIssuer::class)->issue($this->car, 'XX1', $this->userWith('Operator'), $this->at($at));
    }

    private function checkout(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    public function test_checkout_charges_the_calculated_price(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        // 10:00 -> 12:30 = 150 min = 3 hourly units at 150
        $closed = $this->checkout()->complete($session, $operator, null, null, $this->at('2026-06-01 12:30'));

        $this->assertSame(ParkingSession::STATUS_PAID, $closed->status);
        $this->assertSame(150, $closed->duration_minutes);
        $this->assertSame(450.0, (float) $closed->calculated_price);
        $this->assertSame(450.0, (float) $closed->final_price);
        $this->assertNull($closed->adjusted_by);
        $this->assertSame($operator->id, $closed->exit_user_id);
        $this->assertSame(0, AuditLog::where('event', 'price.adjusted')->count());
    }

    public function test_price_comes_from_the_snapshot_not_the_live_tariff(): void
    {
        $session = $this->enter();

        // Admin changes the price after the car has entered.
        Tariff::query()->update(['price_per_unit' => 999]);

        $closed = $this->checkout()->complete($session, $this->userWith('Operator'), null, null, $this->at('2026-06-01 11:00'));

        $this->assertSame(150.0, (float) $closed->final_price, 'Ticket keeps the price it was issued under');
    }

    public function test_a_ticket_cannot_be_checked_out_twice(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $this->checkout()->complete($session, $operator, null, null, $this->at('2026-06-01 11:00'));

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('already closed');

        $this->checkout()->complete($session->fresh(), $operator, null, null, $this->at('2026-06-01 11:30'));
    }

    public function test_operator_without_adjust_price_cannot_change_the_price(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        $this->expectException(AuthorizationException::class);

        try {
            $this->checkout()->complete($session, $operator, 0.0, 'friend', $this->at('2026-06-01 11:00'));
        } finally {
            $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->fresh()->status);
        }
    }

    public function test_operator_cannot_change_the_price_through_the_checkout_screen(): void
    {
        // A crafted request sets finalPrice directly. The server must still refuse it.
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('finalPrice', '1')
            ->set('reason', 'nice try')
            ->call('confirm')
            ->assertSet('error', 'You are not allowed to change the price.')
            ->assertNotDispatched('print-ticket');

        $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Enter a reason');

        $this->checkout()->complete($session, $manager, 100.0, '   ', $this->at('2026-06-01 11:00'));
    }

    public function test_manager_can_discount_within_the_role_limit_and_it_is_audited(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager'); // 20% limit

        // 150 calculated, 120 final = 20% discount, exactly at the limit
        $closed = $this->checkout()->complete($session, $manager, 120.0, 'Regular customer', $this->at('2026-06-01 11:00'));

        $this->assertSame(120.0, (float) $closed->final_price);
        $this->assertSame(150.0, (float) $closed->calculated_price);
        $this->assertSame('Regular customer', $closed->adjustment_reason);
        $this->assertSame($manager->id, $closed->adjusted_by);

        $entry = AuditLog::where('event', 'price.adjusted')->firstOrFail();
        $this->assertSame($manager->id, $entry->user_id);
        $this->assertSame(150.0, (float) $entry->old_values['final_price']);
        $this->assertSame(120.0, (float) $entry->new_values['final_price']);
        $this->assertSame('Regular customer', $entry->reason);
    }

    public function test_discount_above_the_role_limit_is_refused(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager'); // 20% limit

        // 150 -> 100 is a 33.33% discount
        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('above your limit of 20%');

        $this->checkout()->complete($session, $manager, 100.0, 'too much', $this->at('2026-06-01 11:00'));
    }

    public function test_admin_has_no_discount_limit(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $closed = $this->checkout()->complete($session, $admin, 0.0, 'Owner courtesy', $this->at('2026-06-01 11:00'));

        $this->assertSame(0.0, (float) $closed->final_price);
    }

    public function test_surcharge_needs_permission_and_reason_but_no_discount_limit(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');

        $closed = $this->checkout()->complete($session, $manager, 200.0, 'Late pickup fee', $this->at('2026-06-01 11:00'));

        $this->assertSame(200.0, (float) $closed->final_price);
    }

    public function test_negative_price_is_refused(): void
    {
        $session = $this->enter();

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('cannot be negative');

        $this->checkout()->complete($session, $this->userWith('Admin'), -5.0, 'oops', $this->at('2026-06-01 11:00'));
    }

    public function test_discount_limit_comes_from_the_role_not_a_direct_grant(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $operator->givePermissionTo('adjust_price'); // extra permission, but limit stays 0%

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('above your limit of 0%');

        $this->checkout()->complete($session, $operator->fresh(), 140.0, 'small discount', $this->at('2026-06-01 11:00'));
    }

    public function test_checkout_screen_lookup_refuses_unknown_and_closed_tickets(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', 'NOPE1234')
            ->call('lookup')
            ->assertSet('error', 'No ticket with code NOPE1234.');

        $this->checkout()->complete($session, $operator, null, null, $this->at('2026-06-01 11:00'));

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->assertSet('error', 'Ticket '.$session->ticket_code.' is already closed (paid).');
    }

    public function test_checkout_screen_confirm_prints_the_receipt(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('confirm')
            ->assertSet('error', null)
            ->assertDispatched('print-ticket');

        $this->assertSame(ParkingSession::STATUS_PAID, $session->fresh()->status);
    }

    public function test_operator_checkout_screen_hides_the_price_field(): void
    {
        $session = $this->enter();

        Livewire::actingAs($this->userWith('Operator'))
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->assertDontSee('Final price')
            ->assertSee('Confirm and print receipt');
    }

    public function test_user_without_checkout_permission_cannot_open_checkout(): void
    {
        $this->actingAs(User::factory()->create())->get('/operator/checkout')->assertForbidden();
    }
}
