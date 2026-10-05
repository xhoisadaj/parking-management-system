<?php

namespace Tests\Feature\Statistics;

use App\Models\ParkingSession;
use App\Models\User;
use App\Services\TicketIssuer;
use Carbon\Carbon;
use Tests\Feature\Operations\OperationsTestCase;

class DashboardAndExportTest extends OperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00', 'Europe/Tirane'));

        app(TicketIssuer::class)->issue($this->car, 'EXP1', $this->userWith('Operator'), $this->at('2026-06-10 09:00'));
    }

    public function test_manager_sees_the_statistics_dashboard(): void
    {
        $this->actingAs($this->userWith('Manager'))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Export Excel')
            ->assertSee('Export CSV');
    }

    public function test_each_statistics_widget_renders_for_a_manager(): void
    {
        $manager = $this->userWith('Manager');
        $this->actingAs($manager);

        \Livewire\Livewire::test(\App\Filament\Widgets\RevenueByVehicleChart::class, ['filters' => ['preset' => 'month']])->assertOk();
        \Livewire\Livewire::test(\App\Filament\Widgets\OccupancyHeatmap::class, ['filters' => ['preset' => 'month']])->assertSee('Peak times')->assertSee('occupancy-heatmap', false);
        \Livewire\Livewire::test(\App\Filament\Widgets\UserPerformance::class, ['filters' => ['preset' => 'month']])->assertSee('Operators');
        \Livewire\Livewire::test(\App\Filament\Widgets\ImpactOverview::class, ['filters' => ['preset' => 'month']])->assertSee('Calculated')->assertSee('Lost-ticket fees');
        \Livewire\Livewire::test(\App\Filament\Widgets\LiveOccupancyOverview::class)->assertSee('Free spots');
    }

    public function test_operator_cannot_see_the_dashboard(): void
    {
        $this->actingAs($this->userWith('Operator'))->get('/admin')->assertForbidden();
    }

    public function test_manager_downloads_an_excel_workbook(): void
    {
        $response = $this->actingAs($this->userWith('Manager'))
            ->get('/statistics/export?format=xlsx&preset=month&from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('parking-statistics-20260601-20260630.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_manager_downloads_a_csv_of_tickets(): void
    {
        $response = $this->actingAs($this->userWith('Manager'))
            ->get('/statistics/export?format=csv&preset=today&from=2026-06-10&to=2026-06-10');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));

        $csv = $response->getContent() ?: file_get_contents($response->getFile()->getPathname());
        $this->assertStringContainsString('"Ticket","Vehicle","Plate"', $csv);
        $this->assertStringContainsString('EXP1', $csv);
    }

    public function test_operator_cannot_download_statistics(): void
    {
        $this->actingAs($this->userWith('Operator'))
            ->get('/statistics/export?format=csv')
            ->assertForbidden();
    }

    public function test_export_rejects_unknown_formats(): void
    {
        $this->actingAs($this->userWith('Manager'))
            ->get('/statistics/export?format=pdf')
            ->assertStatus(302); // validation redirects back for web requests
    }

    public function test_guests_cannot_download_statistics(): void
    {
        $this->get('/statistics/export?format=csv')->assertRedirect(route('login'));
    }
}
