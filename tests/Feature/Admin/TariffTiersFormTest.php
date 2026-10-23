<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\TariffResource\Pages\CreateTariff;
use App\Filament\Resources\TariffResource\Pages\EditTariff;
use App\Models\Tariff;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Tests\Feature\Operations\OperationsTestCase;

class TariffTiersFormTest extends OperationsTestCase
{
    private \Closure $undoRepeaterFake;

    protected function setUp(): void
    {
        parent::setUp();

        // Plain list keys instead of random UUIDs, so tests can fill the repeater directly.
        $this->undoRepeaterFake = Repeater::fake();
        $this->actingAs($this->userWith('Admin'));
    }

    protected function tearDown(): void
    {
        ($this->undoRepeaterFake)();

        parent::tearDown();
    }

    /** @param  list<array{up_to_minutes: ?int, price: float}>  $tiers */
    private function create(array $tiers)
    {
        return Livewire::test(CreateTariff::class)
            ->fillForm([
                'vehicle_type_id' => $this->car->id,
                'name' => 'Shkallë',
                'billing_unit_minutes' => 60,
                'price_per_unit' => 150,
                'grace_minutes' => 10,
                'is_active' => true,
                'durationTiers' => array_map(fn (array $tier) => ['label' => null] + $tier, $tiers),
            ])
            ->call('create');
    }

    public function test_tiers_ending_in_one_open_tier_are_saved(): void
    {
        $this->create([
            ['up_to_minutes' => 120, 'price' => 100],
            ['up_to_minutes' => 600, 'price' => 200],
            ['up_to_minutes' => null, 'price' => 400],
        ])->assertHasNoFormErrors();

        $this->assertSame(3, Tariff::where('name', 'Shkallë')->firstOrFail()->durationTiers()->count());
    }

    public function test_tiers_without_an_open_tier_are_refused(): void
    {
        $this->create([
            ['up_to_minutes' => 120, 'price' => 100],
            ['up_to_minutes' => 600, 'price' => 200],
            ['up_to_minutes' => 720, 'price' => 400],
        ])->assertHasFormErrors(['durationTiers']);

        $this->assertNull(Tariff::where('name', 'Shkallë')->first());
    }

    public function test_more_than_one_open_tier_is_refused(): void
    {
        $this->create([
            ['up_to_minutes' => 120, 'price' => 100],
            ['up_to_minutes' => null, 'price' => 200],
            ['up_to_minutes' => null, 'price' => 400],
        ])->assertHasFormErrors(['durationTiers']);
    }

    public function test_two_tiers_with_the_same_cutoff_are_refused(): void
    {
        $this->create([
            ['up_to_minutes' => 120, 'price' => 100],
            ['up_to_minutes' => 120, 'price' => 150],
            ['up_to_minutes' => null, 'price' => 400],
        ])->assertHasFormErrors(['durationTiers']);
    }

    public function test_the_open_tier_may_sit_anywhere_in_the_list(): void
    {
        // The calculator sorts by cutoff, so the order typed in doesn't matter.
        $this->create([
            ['up_to_minutes' => null, 'price' => 400],
            ['up_to_minutes' => 600, 'price' => 200],
            ['up_to_minutes' => 120, 'price' => 100],
        ])->assertHasNoFormErrors();
    }

    public function test_a_tariff_without_tiers_is_unaffected(): void
    {
        $this->create([])->assertHasNoFormErrors();
    }

    public function test_editing_away_the_open_tier_is_refused(): void
    {
        $this->create([
            ['up_to_minutes' => 120, 'price' => 100],
            ['up_to_minutes' => null, 'price' => 400],
        ])->assertHasNoFormErrors();
        $tariff = Tariff::where('name', 'Shkallë')->firstOrFail();

        Livewire::test(EditTariff::class, ['record' => $tariff->getRouteKey()])
            ->fillForm(['durationTiers' => [
                ['label' => null, 'up_to_minutes' => 120, 'price' => 100],
                ['label' => null, 'up_to_minutes' => 720, 'price' => 400],
            ]])
            ->call('save')
            ->assertHasFormErrors(['durationTiers']);

        $this->assertSame(1, $tariff->durationTiers()->whereNull('up_to_minutes')->count());
    }
}
