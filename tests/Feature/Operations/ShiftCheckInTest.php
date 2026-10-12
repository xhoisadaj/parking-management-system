<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\ShiftScreen;
use App\Models\Setting;
use App\Models\Shift;
use App\Services\ShiftService;
use Livewire\Livewire;

class ShiftCheckInTest extends OperationsTestCase
{
    public function test_entry_checkout_and_lost_ticket_redirect_to_the_shift_screen_when_no_shift_is_open(): void
    {
        $operator = $this->userWith('Operator');

        $this->actingAs($operator)->get('/operator/entry')->assertRedirect(route('operator.shift'));
        $this->actingAs($operator)->get('/operator/checkout')->assertRedirect(route('operator.shift'));
        $this->actingAs($operator)->get('/operator/lost')->assertRedirect(route('operator.shift'));
    }

    public function test_the_redirect_explains_why_on_the_shift_screen(): void
    {
        $operator = $this->userWith('Operator');

        $this->actingAs($operator)
            ->get('/operator/entry')
            ->assertRedirect(route('operator.shift'))
            ->assertSessionHas('shift_required', true);

        $this->actingAs($operator)
            ->followingRedirects()
            ->get('/operator/entry')
            ->assertSee('Duhet të filloni turnin');
    }

    public function test_entry_checkout_and_lost_ticket_work_once_a_shift_is_open(): void
    {
        $operator = $this->userWith('Operator');
        app(ShiftService::class)->ensureOpen($operator);

        $this->actingAs($operator)->get('/operator/entry')->assertOk();
        $this->actingAs($operator)->get('/operator/checkout')->assertOk();
        $this->actingAs($operator)->get('/operator/lost')->assertOk();
    }

    public function test_shift_screen_shows_a_check_in_button_when_no_shift_is_open(): void
    {
        Livewire::actingAs($this->userWith('Operator'))
            ->test(ShiftScreen::class)
            ->assertSee('Fillo turnin')
            ->assertDontSee('Mbyll turnin');
    }

    public function test_starting_the_shift_opens_it_and_unlocks_the_other_screens(): void
    {
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->set('openingCash', '500')
            ->call('startShift')
            ->assertSee('Mbyll turnin');

        $shift = app(ShiftService::class)->currentOpen($operator);
        $this->assertNotNull($shift);
        $this->assertSame(500.0, (float) $shift->opening_cash);
        $this->actingAs($operator)->get('/operator/entry')->assertOk();
    }

    public function test_declaring_zero_in_the_drawer_is_accepted(): void
    {
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->set('openingCash', '0')
            ->call('startShift')
            ->assertSee('Mbyll turnin')
            ->assertSet('error', null);

        $this->assertSame(0.0, (float) app(ShiftService::class)->currentOpen($operator)->opening_cash);
    }

    public function test_starting_a_shift_without_declaring_the_drawer_is_refused(): void
    {
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->call('startShift')
            ->assertSee('Fillo turnin') // still on the "no shift" screen
            ->assertSet('error', fn (?string $error) => $error !== null);

        $this->assertNull(app(ShiftService::class)->currentOpen($operator));
    }

    public function test_a_negative_opening_amount_is_refused(): void
    {
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->set('openingCash', '-50')
            ->call('startShift')
            ->assertSet('error', fn (?string $error) => $error !== null);

        $this->assertNull(app(ShiftService::class)->currentOpen($operator));
    }

    public function test_closing_a_shift_does_not_reopen_one_automatically(): void
    {
        $operator = $this->userWith('Operator');
        app(ShiftService::class)->ensureOpen($operator);

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->set('closingCash', '0')
            ->call('closeShift')
            ->assertSee('Fillo turnin');

        $this->assertNull(app(ShiftService::class)->currentOpen($operator));
        $this->actingAs($operator)->get('/operator/entry')->assertRedirect(route('operator.shift'));
    }

    public function test_closing_a_shift_without_declaring_the_drawer_is_refused(): void
    {
        $operator = $this->userWith('Operator');
        app(ShiftService::class)->ensureOpen($operator);

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->call('closeShift')
            ->assertSet('error', fn (?string $error) => $error !== null)
            ->assertDontSee('Fillo turnin');

        $this->assertNotNull(app(ShiftService::class)->currentOpen($operator), 'The shift must stay open until the drawer is declared');
    }

    public function test_closing_cash_is_saved_on_the_shift(): void
    {
        $operator = $this->userWith('Operator');
        app(ShiftService::class)->ensureOpen($operator);

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->set('closingCash', '320')
            ->call('closeShift');

        $closed = Shift::where('user_id', $operator->id)->latest('id')->first();
        $this->assertSame(320.0, (float) $closed->closing_cash_declared);
    }

    // ----- Admin-configurable: require_cash_declaration -----------------------

    public function test_when_cash_declaration_is_turned_off_clocking_in_needs_no_field(): void
    {
        Setting::current()->update(['require_cash_declaration' => false]);
        $operator = $this->userWith('Operator');

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->assertDontSee('Sa para ka në arkë')
            ->call('startShift')
            ->assertSee('Mbyll turnin')
            ->assertSet('error', null);

        $shift = app(ShiftService::class)->currentOpen($operator);
        $this->assertNotNull($shift);
        $this->assertNull($shift->opening_cash);
    }

    public function test_when_cash_declaration_is_turned_off_closing_needs_no_field(): void
    {
        Setting::current()->update(['require_cash_declaration' => false]);
        $operator = $this->userWith('Operator');
        app(ShiftService::class)->ensureOpen($operator);

        Livewire::actingAs($operator)
            ->test(ShiftScreen::class)
            ->set('confirmingClose', true)
            ->assertDontSee('Sa para ka në arkë')
            ->call('closeShift')
            ->assertSee('Fillo turnin')
            ->assertSet('error', null);

        $this->assertNull(app(ShiftService::class)->currentOpen($operator));
        $closed = Shift::where('user_id', $operator->id)->latest('id')->first();
        $this->assertNull($closed->closing_cash_declared);
    }

    public function test_cash_declaration_is_required_by_default(): void
    {
        // No setting change: the default (true) must still enforce it.
        Livewire::actingAs($this->userWith('Operator'))
            ->test(ShiftScreen::class)
            ->assertSee('Sa para ka në arkë');
    }
}
