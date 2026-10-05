<?php

namespace Tests\Feature\Operations;

use App\Models\ParkingSession;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Printing\BrowserTicketPrinter;
use App\Services\Printing\TicketPrinter;
use App\Services\TicketIssuer;

class PrintTest extends OperationsTestCase
{
    private function enter(): ParkingSession
    {
        return app(TicketIssuer::class)->issue($this->car, 'PR1', $this->userWith('Operator'), $this->at('2026-06-01 10:00'));
    }

    public function test_the_printer_interface_is_bound_to_the_browser_driver(): void
    {
        $this->assertInstanceOf(BrowserTicketPrinter::class, app(TicketPrinter::class));
    }

    public function test_print_job_points_at_the_entry_page(): void
    {
        $session = $this->enter();

        $job = app(TicketPrinter::class)->printEntry($session);

        $this->assertSame('browser', $job->mode);
        $this->assertSame(route('print.entry', $session), $job->url);
    }

    public function test_entry_ticket_shows_the_code_barcode_and_header_but_no_price(): void
    {
        $session = $this->enter();

        $html = $this->actingAs($this->userWith('Operator'))
            ->get(route('print.entry', $session))
            ->assertOk()
            ->assertSee($session->ticket_code)
            ->assertSee('City Center Parking')
            ->assertSee('Welcome to City Center Parking')
            ->assertSee('PR1')
            ->assertSee('<svg', false)
            ->assertSee('size: 80mm auto', false)
            ->getContent();

        $this->assertStringNotContainsString('TOTAL', $html);
        $this->assertStringNotContainsString('window.print', $html, 'Only opens printing when asked to');
    }

    public function test_entry_ticket_prints_automatically_when_requested_by_the_screen(): void
    {
        $session = $this->enter();

        $this->actingAs($this->userWith('Operator'))
            ->get(route('print.entry', $session).'?print=1')
            ->assertOk()
            ->assertSee('window.print()', false);
    }

    public function test_receipt_shows_the_total_and_the_operator_who_closed_the_ticket(): void
    {
        $session = $this->enter();
        $cashier = User::factory()->create(['name' => 'Ana Operator']);
        $cashier->assignRole('Operator');
        app(CheckoutService::class)->complete($session, $cashier, null, null, $this->at('2026-06-01 11:00'));

        $this->actingAs($cashier)
            ->get(route('print.receipt', $session))
            ->assertOk()
            ->assertSee('EXIT RECEIPT')
            ->assertSee('150.00')
            ->assertSee('Ana Operator');
    }

    public function test_receipt_is_refused_for_a_ticket_that_is_still_open(): void
    {
        $session = $this->enter();

        $this->actingAs($this->userWith('Operator'))
            ->get(route('print.receipt', $session))
            ->assertStatus(409);
    }

    public function test_user_without_entry_permission_cannot_print_entry_tickets(): void
    {
        $session = $this->enter();

        $this->actingAs(User::factory()->create())
            ->get(route('print.entry', $session))
            ->assertForbidden();
    }

    public function test_printing_needs_a_signed_in_user(): void
    {
        $session = $this->enter();

        $this->get(route('print.entry', $session))->assertRedirect(route('login'));
    }
}
