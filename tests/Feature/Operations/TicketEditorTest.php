<?php

namespace Tests\Feature\Operations;

use App\Exceptions\ParkingException;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Models\AuditLog;
use App\Models\ParkingSession;
use App\Models\Tariff;
use App\Models\VehicleType;
use App\Services\CheckoutService;
use App\Services\TicketEditor;
use App\Services\TicketIssuer;
use Livewire\Livewire;

class TicketEditorTest extends OperationsTestCase
{
    private function enter(string $at = '2026-06-01 10:00'): ParkingSession
    {
        $this->travelTo($this->at($at));

        return app(TicketIssuer::class)->issue($this->car, 'XX1', $this->userWith('Operator'), $this->at($at));
    }

    private function editor(): TicketEditor
    {
        return app(TicketEditor::class);
    }

    public function test_admin_corrects_the_plate(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $edited = $this->editor()->edit($session, $admin, $this->car, 'AA123BB', $session->entered_at);

        $this->assertSame('AA123BB', $edited->plate);
    }

    public function test_plate_is_normalized_the_same_way_as_at_entry(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $edited = $this->editor()->edit($session, $admin, $this->car, ' aa-123 bb ', $session->entered_at);

        $this->assertSame('AA123BB', $edited->plate);
    }

    public function test_plate_can_be_cleared(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $edited = $this->editor()->edit($session, $admin, $this->car, null, $session->entered_at);

        $this->assertNull($edited->plate);
    }

    public function test_changing_vehicle_type_retakes_the_tariff_snapshot(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();

        $edited = $this->editor()->edit($session, $admin, $motorbike, $session->plate, $session->entered_at);

        $this->assertSame($motorbike->id, $edited->vehicle_type_id);
        $this->assertSame(50.0, (float) $edited->tariff_snapshot['price_per_unit']);
    }

    public function test_corrected_vehicle_type_is_priced_correctly_at_checkout(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();

        $this->editor()->edit($session, $admin, $motorbike, $session->plate, $session->entered_at);

        // 1 hour at the motorbike rate (50), not the car rate (150) it was issued under.
        $result = app(CheckoutService::class)->calculate($session->fresh(), $this->at('2026-06-01 11:00'));

        $this->assertSame(50.0, $result->total());
    }

    public function test_changing_only_the_entry_time_also_retakes_the_snapshot(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $edited = $this->editor()->edit($session, $admin, $this->car, $session->plate, $this->at('2026-06-01 09:30'));

        $this->assertTrue($this->at('2026-06-01 09:30')->equalTo($edited->entered_at));
    }

    public function test_changing_neither_vehicle_nor_time_leaves_the_snapshot_untouched(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $originalSnapshot = $session->tariff_snapshot;

        $edited = $this->editor()->edit($session, $admin, $this->car, 'NEWPLATE', $session->entered_at);

        $this->assertSame($originalSnapshot, $edited->tariff_snapshot);
    }

    public function test_a_closed_ticket_cannot_be_edited(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        app(CheckoutService::class)->complete($session, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Vetëm biletat aktive mund të ndryshohen');

        $this->editor()->edit($session->fresh(), $this->userWith('Admin'), $this->car, 'X', $this->at('2026-06-01 10:00'));
    }

    public function test_entry_time_cannot_be_set_in_the_future(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('nuk mund të jetë në të ardhmen');

        $this->editor()->edit($session, $admin, $this->car, $session->plate, $this->at('2026-06-01 10:00')->addHour());
    }

    public function test_no_valid_tariff_for_the_new_vehicle_type_is_refused(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $van = VehicleType::where('slug', 'van')->firstOrFail();
        Tariff::where('vehicle_type_id', $van->id)->update(['is_active' => false]);

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Nuk ka tarifë aktive');

        $this->editor()->edit($session, $admin, $van, $session->plate, $session->entered_at);
    }

    public function test_edit_is_recorded_in_the_audit_log(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();

        $this->editor()->edit($session, $admin, $motorbike, 'NEWPLATE', $session->entered_at);

        $entry = AuditLog::where('event', 'ticket.edited')->firstOrFail();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame($this->car->id, $entry->old_values['vehicle_type_id']);
        $this->assertSame($motorbike->id, $entry->new_values['vehicle_type_id']);
        $this->assertSame('XX1', $entry->old_values['plate']);
        $this->assertSame('NEWPLATE', $entry->new_values['plate']);
    }

    public function test_admin_edits_a_ticket_through_the_panel_action(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('edit', $session, data: [
                'vehicle_type_id' => $motorbike->id,
                'plate' => 'PANEL1',
                'entered_at' => $session->entered_at,
            ])
            ->assertHasNoTableActionErrors();

        $session->refresh();
        $this->assertSame('PANEL1', $session->plate);
        $this->assertSame($motorbike->id, $session->vehicle_type_id);
        $this->assertSame(50.0, (float) $session->tariff_snapshot['price_per_unit']);
    }

    // ----- Price override on an active ticket --------------------------------

    public function test_admin_sets_a_custom_price_on_an_active_ticket(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $edited = $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');

        $this->assertSame(777.0, (float) $edited->final_price);
        $this->assertSame('VIP rate', $edited->adjustment_reason);
        $this->assertSame($admin->id, $edited->adjusted_by);
        $this->assertSame(ParkingSession::STATUS_ACTIVE, $edited->status, 'Setting a price does not check the ticket out');
    }

    public function test_the_override_is_charged_at_checkout_without_the_operators_own_permission(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');

        $operator = $this->userWith('Operator'); // no adjust_price
        $this->travelTo($this->at('2026-06-01 11:00'));
        $closed = app(CheckoutService::class)->complete($session->fresh(), $operator, null, null, $this->at('2026-06-01 11:00'), 777.0);

        $this->assertSame(777.0, (float) $closed->final_price);
        $this->assertSame(150.0, (float) $closed->calculated_price, 'The true calculation is still recorded');
        $this->assertSame($admin->id, $closed->adjusted_by, 'The admin who set the price is credited, not the operator who scanned it out');
        $this->assertSame('VIP rate', $closed->adjustment_reason);
    }

    public function test_the_operator_can_still_type_their_own_price_over_the_preset(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');

        $manager = $this->userWith('Manager'); // has adjust_price
        $this->travelTo($this->at('2026-06-01 11:00'));
        $closed = app(CheckoutService::class)->complete($session->fresh(), $manager, 500.0, 'actually a different rate', $this->at('2026-06-01 11:00'), 500.0);

        $this->assertSame(500.0, (float) $closed->final_price);
        $this->assertSame($manager->id, $closed->adjusted_by, 'The operator explicitly overrode it, so it is credited to them');
    }

    public function test_setting_an_override_requires_the_adjust_price_permission(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator'); // no adjust_price

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->editor()->edit($session, $operator, $this->car, $session->plate, $session->entered_at, 777.0, 'nice try');
    }

    public function test_setting_an_override_requires_a_reason(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Shkruani arsyen e çmimit');

        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, '  ');
    }

    public function test_an_override_below_the_roles_discount_limit_is_refused(): void
    {
        $session = $this->enter();
        $manager = $this->userWith('Manager'); // 20% limit
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 calculated

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('është mbi limitin tuaj prej 20%');

        $this->editor()->edit($session, $manager, $this->car, $session->plate, $session->entered_at, 100.0, 'too much off');
    }

    public function test_leaving_the_override_blank_clears_a_previous_one(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');

        $edited = $this->editor()->edit($session->fresh(), $admin, $this->car, $session->plate, $session->entered_at);

        $this->assertNull($edited->final_price);
        $this->assertNull($edited->adjustment_reason);
        $this->assertNull($edited->adjusted_by);
    }

    public function test_price_override_is_recorded_in_the_audit_log(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');

        $entry = AuditLog::where('event', 'ticket.price_override_set')->firstOrFail();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame(777.0, (float) $entry->new_values['final_price']);
        $this->assertSame('VIP rate', $entry->reason);
    }

    public function test_admin_sets_a_price_override_through_the_panel_action(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('edit', $session, data: [
                'vehicle_type_id' => $this->car->id,
                'plate' => $session->plate,
                'entered_at' => $session->entered_at,
                'price_override' => 777.0,
                'price_override_reason' => 'VIP rate',
            ])
            ->assertHasNoTableActionErrors();

        $session->refresh();
        $this->assertSame(777.0, (float) $session->final_price);
        $this->assertSame('VIP rate', $session->adjustment_reason);
    }

    public function test_the_checkout_screen_shows_the_preset_price_to_every_operator(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');

        $operator = $this->userWith('Operator'); // no adjust_price — would normally never see a price field

        Livewire::actingAs($operator)
            ->test(\App\Livewire\Operator\CheckoutScreen::class)
            ->set('code', $session->ticket_code)
            ->call('lookup')
            ->assertSee('Çmimi i caktuar nga '.$admin->name)
            ->assertSee('777,00')
            ->assertSet('chargeable', 777.0);
    }

    public function test_the_edit_action_is_hidden_only_for_a_voided_ticket(): void
    {
        // One button covers both cases (active: plate/vehicle/time, closed: final price) so a
        // closed ticket keeps "Ndrysho" visible too — only a voided ticket has nothing to edit.
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        app(CheckoutService::class)->complete($session, $operator, null, null, $this->at('2026-06-01 11:00'), 150.0);
        $voided = $this->enter('2026-06-01 10:05');
        app(\App\Services\VoidService::class)->void($voided, $this->userWith('Manager'), 'issued by mistake');

        $component = Livewire::actingAs($this->userWith('Admin'))->test(ListTickets::class);

        $component->assertTableActionVisible('edit', $session->fresh());
        $component->assertTableActionHidden('edit', $voided->fresh());
    }

    // ----- Price correction on a closed ticket -------------------------------

    private function pay(ParkingSession $session, string $at = '2026-06-01 11:00'): ParkingSession
    {
        app(CheckoutService::class)->complete($session, $this->userWith('Operator'), null, null, $this->at($at), 150.0);

        return $session->fresh();
    }

    public function test_admin_corrects_the_final_price_of_a_paid_ticket(): void
    {
        $session = $this->pay($this->enter());
        $admin = $this->userWith('Admin');

        $corrected = $this->editor()->correctPrice($session, $admin, 100.0, 'Receipt was wrong');

        $this->assertSame(100.0, (float) $corrected->final_price);
        $this->assertSame('Receipt was wrong', $corrected->adjustment_reason);
        $this->assertSame($admin->id, $corrected->adjusted_by);
        $this->assertSame(150.0, (float) $corrected->calculated_price, 'The original calculation stays intact');
    }

    public function test_price_correction_is_recorded_in_the_audit_log(): void
    {
        $session = $this->pay($this->enter());
        $admin = $this->userWith('Admin');

        $this->editor()->correctPrice($session, $admin, 100.0, 'Receipt was wrong');

        $entry = AuditLog::where('event', 'price.corrected')->firstOrFail();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame(150.0, (float) $entry->old_values['final_price']);
        $this->assertSame(100.0, (float) $entry->new_values['final_price']);
        $this->assertSame('Receipt was wrong', $entry->reason);
    }

    public function test_an_active_ticket_cannot_have_its_price_corrected(): void
    {
        $session = $this->enter();

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Vetëm biletat e mbyllura');

        $this->editor()->correctPrice($session, $this->userWith('Admin'), 100.0, 'too early');
    }

    public function test_price_correction_requires_a_reason(): void
    {
        $session = $this->pay($this->enter());

        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('Shkruani arsyen');

        $this->editor()->correctPrice($session, $this->userWith('Admin'), 100.0, '   ');
    }

    public function test_price_correction_requires_the_adjust_price_permission(): void
    {
        $session = $this->pay($this->enter());
        $operator = $this->userWith('Operator'); // no adjust_price

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->editor()->correctPrice($session, $operator, 100.0, 'nice try');
    }

    public function test_price_correction_respects_the_roles_discount_limit(): void
    {
        $session = $this->pay($this->enter());
        $manager = $this->userWith('Manager'); // 20% limit

        // 150 -> 100 is a 33% discount, over the limit.
        $this->expectException(ParkingException::class);
        $this->expectExceptionMessage('është mbi limitin tuaj prej 20%');

        $this->editor()->correctPrice($session, $manager, 100.0, 'too much');
    }

    public function test_price_correction_increase_is_not_limited_by_the_discount_limit(): void
    {
        $session = $this->pay($this->enter());
        $manager = $this->userWith('Manager');

        $corrected = $this->editor()->correctPrice($session, $manager, 300.0, 'undercharged at checkout');

        $this->assertSame(300.0, (float) $corrected->final_price);
    }

    public function test_a_lost_ticket_fee_can_also_be_corrected(): void
    {
        $session = $this->enter();
        $operator = $this->userWith('Operator');
        app(\App\Services\LostTicketService::class)->markLost($session, $operator, null, 3000.0);

        $corrected = $this->editor()->correctPrice($session->fresh(), $this->userWith('Admin'), 2000.0, 'Regular customer, waived part of the fee');

        $this->assertSame(2000.0, (float) $corrected->final_price);
    }

    public function test_admin_corrects_price_through_the_panel_action(): void
    {
        $session = $this->pay($this->enter());
        $admin = $this->userWith('Admin');

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('edit', $session, data: [
                'final_price' => 100.0,
                'reason' => 'Receipt was wrong',
            ])
            ->assertHasNoTableActionErrors();

        $session->refresh();
        $this->assertSame(100.0, (float) $session->final_price);
    }

    public function test_the_edit_action_shows_the_breakdown_and_an_override_field_for_an_active_ticket(): void
    {
        $session = $this->enter();
        $this->travelTo($this->at('2026-06-01 11:00')); // 1 hour in: something to preview

        Livewire::actingAs($this->userWith('Admin'))
            ->test(ListTickets::class)
            ->mountTableAction('edit', $session)
            ->assertTableActionDataSet(['vehicle_type_id' => $this->car->id])
            ->assertSee('Zbërthimi i çmimit')
            ->assertSee('150,00 ALL')
            ->assertSee('Çmimi i personalizuar');
    }

    // ----- Print action --------------------------------------------------------

    public function test_the_print_action_points_at_the_entry_ticket_for_an_active_session(): void
    {
        $session = $this->enter();

        Livewire::actingAs($this->userWith('Admin'))
            ->test(ListTickets::class)
            ->assertTableActionHasUrl('print', route('print.entry', $session).'?print=1', $session)
            ->assertTableActionShouldOpenUrlInNewTab('print', $session);
    }

    public function test_the_print_action_points_at_the_receipt_for_a_closed_session(): void
    {
        $session = $this->pay($this->enter());

        Livewire::actingAs($this->userWith('Admin'))
            ->test(ListTickets::class)
            ->assertTableActionHasUrl('print', route('print.receipt', $session).'?print=1', $session);
    }

    public function test_the_print_action_is_hidden_for_a_voided_ticket(): void
    {
        $session = $this->enter();
        app(\App\Services\VoidService::class)->void($session, $this->userWith('Manager'), 'issued by mistake');

        Livewire::actingAs($this->userWith('Admin'))
            ->test(ListTickets::class)
            ->assertTableActionHidden('print', $session->fresh());
    }

    // ----- Paying a ticket from the admin panel ------------------------------

    public function test_admin_pays_an_active_ticket_through_the_panel_action(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 due

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('pay', $session, data: [
                'amount_received' => 150.0,
            ])
            ->assertHasNoTableActionErrors();

        $session->refresh();
        $this->assertSame(ParkingSession::STATUS_PAID, $session->status);
        $this->assertSame(150.0, (float) $session->final_price);
        $this->assertSame(150.0, (float) $session->amount_received);
        $this->assertSame($admin->id, $session->exit_user_id);
    }

    public function test_paying_does_not_require_the_admin_to_have_an_open_operator_shift(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->assertNull(app(\App\Services\ShiftService::class)->currentOpen($admin));
        $this->travelTo($this->at('2026-06-01 11:00'));

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('pay', $session, data: ['amount_received' => 150.0])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ParkingSession::STATUS_PAID, $session->fresh()->status);
        $this->assertNotNull(app(\App\Services\ShiftService::class)->currentOpen($admin), 'A shift was opened silently, same as any operator checkout');
    }

    public function test_paying_uses_a_preset_price_by_default(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->editor()->edit($session, $admin, $this->car, $session->plate, $session->entered_at, 777.0, 'VIP rate');
        $this->travelTo($this->at('2026-06-01 11:00'));

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('pay', $session->fresh(), data: ['amount_received' => 777.0])
            ->assertHasNoTableActionErrors();

        $this->assertSame(777.0, (float) $session->fresh()->final_price);
    }

    public function test_admin_can_type_a_different_price_while_paying(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 calculated

        Livewire::actingAs($admin)
            ->test(ListTickets::class)
            ->callTableAction('pay', $session, data: [
                'final_price' => 100.0,
                'reason' => 'negotiated',
                'amount_received' => 100.0,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(100.0, (float) $session->fresh()->final_price);
    }

    public function test_paying_with_too_little_cash_is_refused(): void
    {
        $session = $this->enter();
        $this->travelTo($this->at('2026-06-01 11:00')); // 150 due

        Livewire::actingAs($this->userWith('Admin'))
            ->test(ListTickets::class)
            ->callTableAction('pay', $session, data: ['amount_received' => 50.0]);

        $this->assertSame(ParkingSession::STATUS_ACTIVE, $session->fresh()->status, 'Short cash must not close the ticket');
    }

    public function test_pay_action_is_hidden_for_a_closed_ticket(): void
    {
        $session = $this->pay($this->enter());

        Livewire::actingAs($this->userWith('Admin'))
            ->test(ListTickets::class)
            ->assertTableActionHidden('pay', $session);
    }

    public function test_a_reprinted_entry_ticket_shows_the_edited_details(): void
    {
        $session = $this->enter();
        $admin = $this->userWith('Admin');
        $motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();

        $this->editor()->edit($session, $admin, $motorbike, 'CORRECTED', $session->entered_at);

        // The entry ticket page reads the session fresh from the database, so whatever the
        // operator prints next — including a reprint of this same ticket — reflects the edit.
        $this->actingAs($this->userWith('Operator'))
            ->get(route('print.entry', $session))
            ->assertOk()
            ->assertSee('CORRECTED')
            ->assertSee($motorbike->name)
            ->assertDontSee($this->car->name);
    }
}
