<?php

namespace Tests\Feature\Operations;

use App\Exceptions\ParkingException;
use App\Livewire\Operator\CheckoutScreen;
use App\Livewire\Operator\LostTicketScreen;
use App\Livewire\Operator\ShiftScreen;
use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\CheckoutService;
use App\Services\LostTicketService;
use App\Services\ShiftService;
use App\Services\TicketIssuer;
use Carbon\Carbon;
use Database\Seeders\WorkShiftSeeder;
use Livewire\Livewire;

class CashAndShiftTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkShiftSeeder::class);
    }

    /** Checkout time for the Livewire tests: 10:00 -> 12:30 is 450 for a car. */
    private function atCheckout(): void
    {
        Carbon::setTestNow($this->at('2026-06-01 12:30'));
    }
    /** A car entered at 10:00 and parked for two hours: 2 units at 150 = 300. */
    private function enter(): ParkingSession
    {
        return app(TicketIssuer::class)->issue($this->car, null, $this->userWith('Operator'), $this->at('2026-06-01 10:00'));
    }

    private function checkout(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    // ----- Cash handling ----------------------------------------------------

    public function test_change_is_worked_out_and_stored_on_the_ticket(): void
    {
        $session = $this->enter();

        // 10:00 -> 12:30 = 3 units = 450
        $closed = $this->checkout()->complete($session, $this->userWith('Operator'), null, null, $this->at('2026-06-01 12:30'), 500.0);

        $this->assertSame(500.0, (float) $closed->amount_received);
        $this->assertSame(50.0, (float) $closed->change_given);
        $this->assertSame(450.0, (float) $closed->final_price);
    }

    public function test_exact_cash_gives_no_change(): void
    {
        $session = $this->enter();

        $closed = $this->checkout()->complete($session, $this->userWith('Operator'), null, null, $this->at('2026-06-01 11:00'), 150.0);

        $this->assertSame(0.0, (float) $closed->change_given);
    }

    public function test_cash_short_of_the_price_is_refused_and_the_ticket_stays_open(): void
    {
        $session = $this->enter();

        try {
            $this->checkout()->complete($session, $this->userWith('Operator'), null, null, $this->at('2026-06-01 12:30'), 400.0);
            $this->fail('Short cash should be refused');
        } catch (ParkingException $e) {
            $this->assertSame('The amount received is short by 50.00.', $e->getMessage());
        }

        $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->fresh()->status);
    }

    public function test_cash_is_optional(): void
    {
        $session = $this->enter();

        $closed = $this->checkout()->complete($session, $this->userWith('Operator'), null, null, $this->at('2026-06-01 11:00'));

        $this->assertNull($closed->amount_received);
        $this->assertNull($closed->change_given);
    }

    public function test_lost_ticket_fee_also_takes_cash_and_gives_change(): void
    {
        $session = $this->enter();

        $closed = app(LostTicketService::class)->markLost($session, $this->userWith('Operator'), $this->at('2026-06-01 11:00'), 3500.0);

        $this->assertSame(3000.0, (float) $closed->final_price);
        $this->assertSame(500.0, (float) $closed->change_given);
    }

    public function test_checkout_screen_shows_the_change_and_prints_it(): void
    {
        $session = $this->enter();
        $this->atCheckout();
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('amountReceived', '500')
            ->assertSee('Give back')
            ->assertSee('50.00')
            ->call('confirm')
            ->assertSet('error', null)
            ->assertSet('success', fn (string $message) => str_contains($message, 'Give back 50.00'))
            ->assertDispatched('print-ticket');

        $this->assertSame(50.0, (float) $session->fresh()->change_given);
    }

    public function test_checkout_screen_refuses_short_cash_and_says_by_how_much(): void
    {
        $session = $this->enter();
        $this->atCheckout();

        Livewire::actingAs($this->userWith('Operator'))
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('amountReceived', '400')
            ->assertSee('Short by')
            ->call('confirm')
            ->assertSet('error', 'The amount received is short by 50.00.');

        $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->fresh()->status);
    }

    public function test_cash_entry_accepts_comma_decimals(): void
    {
        $session = $this->enter();
        $this->atCheckout();

        Livewire::actingAs($this->userWith('Operator'))
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('amountReceived', '500,00')
            ->call('confirm')
            ->assertSet('error', null);

        $this->assertSame(50.0, (float) $session->fresh()->change_given);
    }

    public function test_receipt_shows_received_and_change(): void
    {
        $session = $this->enter();
        $cashier = $this->userWith('Operator');
        $this->checkout()->complete($session, $cashier, null, null, $this->at('2026-06-01 11:00'), 200.0);

        $this->actingAs($cashier)
            ->get(route('print.receipt', $session))
            ->assertOk()
            ->assertSee('Received')
            ->assertSee('Change')
            ->assertSee('50.00');
    }

    // ----- Reason rule ------------------------------------------------------

    public function test_small_change_needs_no_reason_when_the_threshold_allows_it(): void
    {
        Setting::current()->update(['reason_threshold_percent' => 20]);
        $session = $this->enter();
        $manager = $this->userWith('Manager');

        // 150 -> 135 is 10%, below the 20% threshold
        $closed = $this->checkout()->complete($session, $manager, 135.0, null, $this->at('2026-06-01 11:00'));

        $this->assertSame(135.0, (float) $closed->final_price);
        $this->assertNull($closed->adjustment_reason);
        $this->assertSame($manager->id, $closed->adjusted_by, 'Still recorded as an adjustment');
    }

    public function test_large_change_needs_a_reason_when_the_threshold_is_reached(): void
    {
        Setting::current()->update(['reason_threshold_percent' => 20]);
        $session = $this->enter();
        $manager = $this->userWith('Manager');

        // 150 -> 195 is a 30% surcharge: it needs a reason. Raising the price has no discount limit.
        try {
            $this->checkout()->complete($session, $manager, 195.0, null, $this->at('2026-06-01 11:00'));
            $this->fail('A 30% change needs a reason');
        } catch (ParkingException $e) {
            $this->assertSame('Enter a reason for changing the price.', $e->getMessage());
        }

        $closed = $this->checkout()->complete($session, $manager, 195.0, 'Late pickup fee', $this->at('2026-06-01 11:00'));
        $this->assertSame('Late pickup fee', $closed->adjustment_reason);
    }

    public function test_change_exactly_at_the_threshold_needs_a_reason(): void
    {
        Setting::current()->update(['reason_threshold_percent' => 20]);
        $session = $this->enter();

        // 150 -> 120 is exactly 20%
        $this->assertTrue($this->checkout()->reasonRequired(150.0, 120.0));
        $this->assertFalse($this->checkout()->reasonRequired(150.0, 130.0));
    }

    public function test_threshold_of_zero_means_every_change_needs_a_reason(): void
    {
        $session = $this->enter();

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Enter a reason');

        $this->checkout()->complete($session, $this->userWith('Manager'), 145.0, null, $this->at('2026-06-01 11:00'));
    }

    public function test_discount_limit_still_applies_without_a_reason(): void
    {
        Setting::current()->update(['reason_threshold_percent' => 50]);
        $session = $this->enter();

        // 150 -> 100 is 33%: no reason needed at a 50% threshold, but the manager's limit is 20%
        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('above your limit of 20%');

        $this->checkout()->complete($session, $this->userWith('Manager'), 100.0, null, $this->at('2026-06-01 11:00'));
    }

    public function test_checkout_screen_marks_the_reason_as_required_only_when_needed(): void
    {
        Setting::current()->update(['reason_threshold_percent' => 20]);
        $session = $this->enter();
        $this->atCheckout();

        Livewire::actingAs($this->userWith('Manager'))
            ->test(CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->set('finalPrice', '400')
            ->assertSee('(optional)')
            ->set('finalPrice', '300')
            ->assertSee('(required for this change)');
    }

    // ----- Work shifts ------------------------------------------------------

    public function test_a_new_shift_takes_the_operators_assigned_work_shift(): void
    {
        $morning = WorkShift::where('name', 'Morning')->firstOrFail();
        $operator = $this->userWith('Operator');
        $operator->update(['work_shift_id' => $morning->id]);

        $shift = app(ShiftService::class)->ensureOpen($operator->fresh(), $this->at('2026-06-01 07:00'));

        $this->assertSame($morning->id, $shift->work_shift_id);
    }

    public function test_an_operator_without_an_assignment_gets_a_shift_with_no_work_shift(): void
    {
        $shift = app(ShiftService::class)->ensureOpen($this->userWith('Operator'), $this->at('2026-06-01 07:00'));

        $this->assertNull($shift->work_shift_id);
    }

    public function test_work_shift_hours_and_midnight_crossing(): void
    {
        $morning = WorkShift::where('name', 'Morning')->firstOrFail();
        $night = WorkShift::create(['name' => 'Night', 'starts_at' => '22:00', 'ends_at' => '06:00', 'is_active' => true]);

        $this->assertSame('06:00 – 14:00', $morning->hoursLabel());
        $this->assertTrue($morning->covers($this->at('2026-06-01 10:00')));
        $this->assertFalse($morning->covers($this->at('2026-06-01 15:00')));

        $this->assertTrue($night->covers($this->at('2026-06-01 23:30')));
        $this->assertTrue($night->covers($this->at('2026-06-01 02:00')));
        $this->assertFalse($night->covers($this->at('2026-06-01 12:00')));
    }

    public function test_my_shift_screen_shows_the_assigned_shift(): void
    {
        $afternoon = WorkShift::where('name', 'Afternoon')->firstOrFail();
        $operator = $this->userWith('Operator');
        $operator->update(['work_shift_id' => $afternoon->id]);

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->assertSee('Afternoon · 14:00 – 22:00')
            ->assertSee('outside your scheduled hours');
    }

    public function test_my_shift_screen_says_when_no_shift_is_assigned(): void
    {
        Livewire::actingAs($this->userWith('Operator'))
            ->test(ShiftScreen::class)
            ->assertSee('No shift has been assigned to you yet.');
    }

    public function test_work_shifts_are_managed_by_managers_in_the_admin_panel(): void
    {
        $this->actingAs($this->userWith('Manager'))->get('/admin/work-shifts')->assertForbidden();
        $this->actingAs($this->userWith('Admin'))->get('/admin/work-shifts')->assertOk();
    }

    public function test_lost_ticket_screen_collects_cash_too(): void
    {
        $session = $this->enter();

        Livewire::actingAs($this->userWith('Operator'))
            ->test(LostTicketScreen::class)
            ->call('select', $session->id)
            ->set('amountReceived', '3500')
            ->call('markLost')
            ->assertSet('error', null);

        $this->assertSame(500.0, (float) $session->fresh()->change_given);
    }
}
