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
        $checkout->complete($a1, $alice, null, null, $this->at('2026-06-01 11:00')); // 150

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

        $this->shifts->reconcile($shift, $manager, 0.0, 'Counted at the desk');

        $shift->refresh();
        $this->assertSame($manager->id, $shift->reconciled_by);
        $this->assertSame('Counted at the desk', $shift->reconciliation_note);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('është verifikuar tashmë');

        $this->shifts->reconcile($shift, $manager, 0.0);
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
}
