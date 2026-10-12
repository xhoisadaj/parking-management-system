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
        $closed = $this->checkout()->complete($session, $operator, null, null, $this->at('2026-06-01 12:30'), 450.0);

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

        $closed = $this->checkout()->complete($session, $this->userWith('Operator'), null, null, $this->at('2026-06-01 11:00'), 150.0);

        $this->assertSame(150.0, (float) $closed->final_price, 'Ticket keeps the price it was issued under');
    }

    public function test_a_ticket_cannot_be_checked_out_twice(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $this->checkout()->complete($session, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('është mbyllur tashmë');

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
            ->assertSet('error', 'Nuk keni leje të ndryshoni çmimin.')
            ->assertNotDispatched('print-ticket');

        $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Shkruani arsyen');

        $this->checkout()->complete($session, $manager, 100.0, '   ', $this->at('2026-06-01 11:00'));
    }

    public function test_manager_can_discount_within_the_role_limit_and_it_is_audited(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager'); // 20% limit

        // 150 calculated, 120 final = 20% discount, exactly at the limit
        $closed = $this->checkout()->complete($session, $manager, 120.0, 'Regular customer', $this->at('2026-06-01 11:00'), 120.0);

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
        $this->expectExceptionMessage('është mbi limitin tuaj prej 20%');

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

        $closed = $this->checkout()->complete($session, $manager, 200.0, 'Late pickup fee', $this->at('2026-06-01 11:00'), 200.0);

        $this->assertSame(200.0, (float) $closed->final_price);
    }

    public function test_negative_price_is_refused(): void
    {
        $session = $this->enter();

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('nuk mund të jetë negativ');

        $this->checkout()->complete($session, $this->userWith('Admin'), -5.0, 'oops', $this->at('2026-06-01 11:00'));
    }

    public function test_discount_limit_comes_from_the_role_not_a_direct_grant(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $operator->givePermissionTo('adjust_price'); // extra permission, but limit stays 0%

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('është mbi limitin tuaj prej 0%');

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
            ->assertSet('error', 'Nuk ka biletë aktive me kodin ose targën "NOPE1234".');

        $this->checkout()->complete($session, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->assertSet('error', 'Bileta '.$session->ticket_code.' është mbyllur tashmë (Paguar).');
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
            ->assertDontSee('Çmimi përfundimtar')
            ->assertSee('Konfirmo dhe printo kuponin');
    }

    public function test_user_without_checkout_permission_cannot_open_checkout(): void
    {
        $this->actingAs(User::factory()->create())->get('/operator/checkout')->assertForbidden();
    }

    // ----- Quick checkout actions --------------------------------------------

    public function test_discount_preset_fills_price_and_a_default_reason(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager'); // 20% limit
        $this->travelTo($this->at('2026-06-01 11:00')); // 60 min = 1 unit @ 150

        Livewire::actingAs($manager)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('applyDiscount', 10.0)
            ->assertSet('finalPrice', '135.00')
            ->assertSet('reason', 'Zbritje 10%');
    }

    public function test_custom_discount_percent_can_be_applied(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin'); // no limit
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 due

        Livewire::actingAs($admin)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('customDiscountPercent', '15')
            ->call('applyCustomDiscount')
            ->assertSet('finalPrice', '127.50')
            ->assertSet('reason', 'Zbritje 15%');
    }

    public function test_clear_adjustment_resets_price_and_reason(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');
        $this->travelTo($this->at('2026-06-01 11:00'));

        Livewire::actingAs($manager)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('applyDiscount', 10.0)
            ->call('clearAdjustment')
            ->assertSet('finalPrice', '')
            ->assertSet('reason', '');
    }

    public function test_reason_suggestion_fills_the_reason_field(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');

        Livewire::actingAs($manager)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('fillReason', 'Klient i rregullt')
            ->assertSet('reason', 'Klient i rregullt');
    }

    public function test_discount_presets_are_hidden_when_the_role_limit_is_zero(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $operator->givePermissionTo('adjust_price'); // extra permission, but limit stays 0%

        Livewire::actingAs($operator->fresh())
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->assertDontSee('10%')
            ->assertDontSee('20%')
            ->assertSee('nuk mund të japë zbritje');
    }

    public function test_exact_amount_button_fills_received_from_the_chargeable_price(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 due

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('useExactAmount')
            ->assertSet('amountReceived', '150.00');
    }

    public function test_quick_pay_charges_the_exact_amount_and_prints_in_one_step(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 due

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('quickPay')
            ->assertSet('error', null)
            ->assertDispatched('print-ticket');

        $closed = $session->fresh();
        $this->assertSame(ParkingSession::STATUS_PAID, $closed->status);
        $this->assertSame(150.0, (float) $closed->final_price);
        $this->assertSame(150.0, (float) $closed->amount_received);
        $this->assertSame(0.0, (float) $closed->change_given);
    }

    public function test_quick_pay_still_applies_an_adjusted_price(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 calculated

        Livewire::actingAs($manager)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->call('applyDiscount', 20.0)
            ->call('quickPay')
            ->assertSet('error', null)
            ->assertDispatched('print-ticket');

        $closed = $session->fresh();
        $this->assertSame(120.0, (float) $closed->final_price);
        $this->assertSame(120.0, (float) $closed->amount_received);
    }

    // ----- Search by plate ---------------------------------------------------

    public function test_a_unique_plate_match_selects_the_ticket_directly(): void
    {
        $operator = $this->userWith('Operator');
        $this->travelTo($this->at('2026-06-01 10:00'));
        $session = app(TicketIssuer::class)->issue($this->car, 'AA123BB', $operator, $this->at('2026-06-01 10:00'));

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', 'aa123bb')
            ->call('lookup')
            ->assertSet('error', null)
            ->assertSee($session->ticket_code);
    }

    public function test_a_partial_plate_with_several_matches_lists_them_to_pick_from(): void
    {
        $operator = $this->userWith('Operator');
        $this->travelTo($this->at('2026-06-01 10:00'));
        $first = app(TicketIssuer::class)->issue($this->car, 'AA111BB', $operator, $this->at('2026-06-01 10:00'));
        $second = app(TicketIssuer::class)->issue($this->car, 'AA222BB', $operator, $this->at('2026-06-01 10:00'));

        $component = Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', 'AA')
            ->call('lookup')
            ->assertSet('error', null)
            ->assertSet('sessionId', null)
            ->assertSee($first->ticket_code)
            ->assertSee($second->ticket_code);

        $component->call('selectResult', $second->id)
            ->assertSet('sessionId', $second->id);
    }

    public function test_plate_search_only_matches_active_tickets(): void
    {
        $operator = $this->userWith('Operator');
        $this->travelTo($this->at('2026-06-01 10:00'));
        $closed = app(TicketIssuer::class)->issue($this->car, 'ZZ999ZZ', $operator, $this->at('2026-06-01 10:00'));
        $this->checkout()->complete($closed, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', 'ZZ999')
            ->call('lookup')
            ->assertSet('error', 'Nuk ka biletë aktive me kodin ose targën "ZZ999".');
    }

    public function test_a_discount_beyond_the_role_limit_is_flagged_live_before_confirming(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager'); // 20% limit
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 calculated

        Livewire::actingAs($manager)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('finalPrice', '100') // 150 -> 100 is a 33.33% discount, over the 20% limit
            ->assertSee('është mbi limitin tuaj prej 20%')
            ->assertSet('blockedFromConfirming', true);
    }

    public function test_a_price_increase_is_not_flagged_as_a_discount(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager');
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 calculated

        Livewire::actingAs($manager)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('finalPrice', '200')
            ->set('reason', 'Late pickup')
            ->assertDontSee('mbi limitin tuaj')
            ->assertSet('blockedFromConfirming', false);
    }

    public function test_letters_in_amount_received_are_flagged_as_invalid(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('amountReceived', 'abc')
            ->assertSee('Shuma duhet të jetë numër');
    }
}
