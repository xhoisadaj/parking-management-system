<?php

namespace Tests\Feature\Operations;

use App\Exceptions\ParkingException;
use App\Filament\Resources\ShiftResource;
use App\Models\ParkingSession;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\ShiftService;
use App\Services\TicketIssuer;

class ShiftTest extends OperationsTestCase
{
    private ShiftService $shifts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shifts = app(ShiftService::class);
    }

    public function test_entering_a_ticket_opens_a_shift_once(): void
    {
        $operator = $this->userWith('Operator');
        $issuer = app(TicketIssuer::class);

        $issuer->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        $issuer->issue($this->car, null, $operator, $this->at('2026-06-01 10:05'));

        $this->assertSame(1, Shift::where('user_id', $operator->id)->count());
    }

    public function test_summary_counts_only_this_operators_activity_in_the_shift(): void
    {
        $alice = $this->userWith('Operator');
        $bob = $this->userWith('Operator');
        $issuer = app(TicketIssuer::class);
        $checkout = app(CheckoutService::class);

        $a1 = $issuer->issue($this->car, null, $alice, $this->at('2026-06-01 10:00'));
        $issuer->issue($this->car, null, $bob, $this->at('2026-06-01 10:01'));
        $checkout->complete($a1, $alice, null, null, $this->at('2026-06-01 11:00'), 150.0); // 150

        $shift = $this->shifts->currentOpen($alice);
        $summary = $this->shifts->summary($shift);

        $this->assertSame(1, $summary['tickets_issued']);
        $this->assertSame(1, $summary['checkouts']);
        $this->assertSame(150.0, $summary['cash_collected']);
    }

    public function test_closing_stores_totals_and_the_next_action_opens_a_new_shift(): void
    {
        $operator = $this->userWith('Operator');
        $issuer = app(TicketIssuer::class);

        $issuer->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        $closed = $this->shifts->close($operator, $this->at('2026-06-01 18:00'));

        $this->assertNotNull($closed->closed_at);
        $this->assertSame(1, $closed->tickets_issued);
        $this->assertNull($this->shifts->currentOpen($operator));

        $issuer->issue($this->car, null, $operator, $this->at('2026-06-01 19:00'));

        $this->assertSame(2, Shift::where('user_id', $operator->id)->count());
    }

    public function test_manager_reconciles_a_closed_shift_once(): void
    {
        $operator = $this->userWith('Operator');
        $manager = $this->userWith('Manager');
        app(TicketIssuer::class)->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        $shift = $this->shifts->close($operator, $this->at('2026-06-01 18:00'));

        $this->shifts->reconcile($shift, $manager, 200.0, 'Counted at the desk');

        $shift->refresh();
        $this->assertSame(200.0, (float) $shift->cash_counted, 'The counted amount must actually be saved, not silently dropped');
        $this->assertSame($manager->id, $shift->reconciled_by);
        $this->assertSame('Counted at the desk', $shift->reconciliation_note);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('është verifikuar tashmë');

        $this->shifts->reconcile($shift, $manager, 0.0);
    }

    public function test_opening_cash_is_declared_only_when_a_shift_is_actually_created(): void
    {
        $operator = $this->userWith('Operator');

        $shift = $this->shifts->ensureOpen($operator, $this->at('2026-06-01 09:00'), 500.0);
        $this->assertSame(500.0, (float) $shift->opening_cash);

        // Calling it again mid-shift (as TicketIssuer/CheckoutService do) must not overwrite it,
        // even if called with a different amount or none at all.
        $sameShift = $this->shifts->ensureOpen($operator, null, 999.0);
        $this->assertSame($shift->id, $sameShift->id);
        $this->assertSame(500.0, (float) $sameShift->fresh()->opening_cash);
    }

    public function test_a_shift_opened_without_a_declared_float_has_no_opening_cash(): void
    {
        // e.g. an admin completing a checkout from the panel without ever clocking in.
        $admin = $this->userWith('Admin');

        $shift = $this->shifts->ensureOpen($admin);

        $this->assertNull($shift->opening_cash);
    }

    public function test_expected_cash_adds_the_opening_float_to_what_was_collected(): void
    {
        $operator = $this->userWith('Operator');
        $this->shifts->ensureOpen($operator, $this->at('2026-06-01 09:00'), 500.0);
        app(TicketIssuer::class)->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        $shift = $this->shifts->close($operator, $this->at('2026-06-01 18:00'));

        // Nothing was checked out, so cash_collected is 0 — expected is just the float.
        $this->assertSame(500.0, $shift->expectedCash());
    }

    public function test_expected_cash_treats_an_undeclared_float_as_zero(): void
    {
        $admin = $this->userWith('Admin');
        $shift = $this->shifts->ensureOpen($admin);
        $shift->update(['cash_collected' => 150.0]);

        $this->assertNull($shift->opening_cash);
        $this->assertSame(150.0, $shift->expectedCash());
    }

    public function test_reconciliation_compares_against_opening_cash_plus_collected(): void
    {
        $operator = $this->userWith('Operator');
        $manager = $this->userWith('Manager');
        $this->shifts->ensureOpen($operator, $this->at('2026-06-01 09:30'), 500.0);
        $ticket = app(TicketIssuer::class)->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        app(CheckoutService::class)->complete($ticket, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);
        $shift = $this->shifts->close($operator, $this->at('2026-06-01 18:00'));

        // Expected: 500 opening + 150 collected = 650. Counted exactly that: no discrepancy.
        $this->shifts->reconcile($shift, $manager, 650.0);

        $this->assertSame(0.0, round((float) $shift->fresh()->cash_counted - $shift->expectedCash(), 2));
    }

    public function test_an_open_shift_cannot_be_reconciled(): void
    {
        $operator = $this->userWith('Operator');
        $shift = $this->shifts->ensureOpen($operator);

        $this->expectException(ParkingException::class);

        $this->shifts->reconcile($shift, $this->userWith('Manager'), 0.0);
    }

    public function test_only_reconciliation_roles_can_see_the_shift_screen(): void
    {
        $this->actingAs($this->userWith('Operator'));
        $this->assertFalse(ShiftResource::canViewAny());

        $this->actingAs($this->userWith('Manager'));
        $this->assertTrue(ShiftResource::canViewAny());
    }

    // ----- Verifying a shift from the admin panel ----------------------------

    public function test_the_reconcile_form_is_pre_filled_with_the_systems_own_total(): void
    {
        $operator = $this->userWith('Operator');
        $ticket = app(TicketIssuer::class)->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        app(CheckoutService::class)->complete($ticket, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);
        $shift = $this->shifts->close($operator, $this->at('2026-06-01 18:00'));

        \Livewire\Livewire::actingAs($this->userWith('Manager'))
            ->test(\App\Filament\Resources\ShiftResource\Pages\ListShifts::class)
            ->mountTableAction('reconcile', $shift)
            ->assertSee('150,00')
            ->assertTableActionDataSet(['cash_counted' => '150.00']);
    }

    public function test_viewing_a_shifts_tickets_shows_exactly_what_makes_up_its_total(): void
    {
        $operator = $this->userWith('Operator');
        $ticket = app(TicketIssuer::class)->issue($this->car, 'XX1', $operator, $this->at('2026-06-01 10:00'));
        app(CheckoutService::class)->complete($ticket, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);
        $shift = $this->shifts->close($operator, $this->at('2026-06-01 18:00'));

        \Livewire\Livewire::actingAs($this->userWith('Manager'))
            ->test(\App\Filament\Resources\ShiftResource\Pages\ListShifts::class)
            ->mountTableAction('view_tickets', $shift)
            ->assertSee($ticket->ticket_code)
            ->assertSee('XX1')
            ->assertSee('150,00');
    }

    public function test_a_price_adjustment_is_flagged_in_the_shifts_ticket_list(): void
    {
        $operator = $this->userWith('Operator');
        $manager = $this->userWith('Manager');
        $ticket = app(TicketIssuer::class)->issue($this->car, null, $operator, $this->at('2026-06-01 10:00'));
        app(CheckoutService::class)->complete($ticket, $manager, 130.0, 'Regular customer', $this->at('2026-06-01 11:00'), 130.0);
        $shift = $this->shifts->close($manager, $this->at('2026-06-01 18:00'));

        \Livewire\Livewire::actingAs($this->userWith('Admin'))
            ->test(\App\Filament\Resources\ShiftResource\Pages\ListShifts::class)
            ->mountTableAction('view_tickets', $shift)
            ->assertSee('ndryshuar')
            ->assertSee('Regular customer');
    }
}
