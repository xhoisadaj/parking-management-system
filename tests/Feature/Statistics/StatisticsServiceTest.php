<?php

namespace Tests\Feature\Statistics;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Statistics\DateRange;
use App\Services\Statistics\StatisticsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Feature\Operations\OperationsTestCase;

class StatisticsServiceTest extends OperationsTestCase
{
    private StatisticsService $stats;

    private VehicleType $motorbike;

    private User $ana;

    private User $mira;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-June so the periods used below are fully in the past.
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00', 'Europe/Tirane'));

        $this->stats = app(StatisticsService::class);
        $this->motorbike = VehicleType::where('slug', 'motorbike')->firstOrFail();
        $this->ana = $this->userWith('Operator');
        $this->mira = $this->userWith('Manager');
    }

    /** Creates a ticket directly. Snapshot comes from the current car or motorbike tariff. */
    private function ticket(array $attributes): ParkingSession
    {
        $type = $attributes['type'] ?? $this->car;

        return ParkingSession::create(array_merge([
            'ticket_code' => strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'vehicle_type_id' => $type->id,
            'entered_at' => $this->at('2026-06-02 10:00'),
            'entry_user_id' => $this->ana->id,
            'status' => ParkingSession::STATUS_ACTIVE,
            'tariff_snapshot' => \App\Models\Tariff::where('vehicle_type_id', $type->id)->firstOrFail()->toSnapshot(),
        ], collect($attributes)->except('type')->all()));
    }

    private function june(): array
    {
        return [$this->at('2026-06-01'), $this->at('2026-06-30')];
    }

    public function test_revenue_counts_paid_and_lost_tickets_by_exit_time_and_ignores_voids(): void
    {
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 11:00'), 'final_price' => 150, 'calculated_price' => 150, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['status' => 'lost', 'exited_at' => $this->at('2026-06-03 09:00'), 'final_price' => 3000, 'calculated_price' => 300, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['type' => $this->motorbike, 'status' => 'paid', 'exited_at' => $this->at('2026-06-02 12:00'), 'final_price' => 50, 'calculated_price' => 50, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['status' => 'void', 'final_price' => 999]);
        // Exited outside the period: not counted.
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-05-31 23:59'), 'final_price' => 777, 'calculated_price' => 777, 'exit_user_id' => $this->ana->id]);

        [$from, $to] = $this->june();

        $this->assertSame(3200.0, $this->stats->revenue($from, $to));
    }

    public function test_revenue_by_vehicle_type_is_split_and_counts_tickets(): void
    {
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 11:00'), 'final_price' => 150, 'calculated_price' => 150, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['status' => 'lost', 'exited_at' => $this->at('2026-06-03 09:00'), 'final_price' => 3000, 'calculated_price' => 300, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['type' => $this->motorbike, 'status' => 'paid', 'exited_at' => $this->at('2026-06-02 12:00'), 'final_price' => 50, 'calculated_price' => 50, 'exit_user_id' => $this->ana->id]);

        [$from, $to] = $this->june();

        $this->assertSame([
            ['vehicle' => 'Car', 'tickets' => 2, 'revenue' => 3150.0],
            ['vehicle' => 'Motorbike', 'tickets' => 1, 'revenue' => 50.0],
        ], $this->stats->revenueByVehicleType($from, $to));
    }

    public function test_calculated_versus_actual_compares_paid_tickets_and_reports_lost_fees_separately(): void
    {
        // Paid at the tariff price.
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 11:00'), 'final_price' => 150, 'calculated_price' => 150, 'exit_user_id' => $this->ana->id]);
        // Paid after a manager discount of 60.
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 13:00'), 'final_price' => 240, 'calculated_price' => 300, 'exit_user_id' => $this->mira->id, 'adjusted_by' => $this->mira->id, 'adjustment_reason' => 'Regular']);
        // Lost ticket: fee is not an adjustment.
        $this->ticket(['status' => 'lost', 'exited_at' => $this->at('2026-06-03 09:00'), 'final_price' => 3000, 'calculated_price' => 300, 'exit_user_id' => $this->ana->id]);

        [$from, $to] = $this->june();
        $figures = $this->stats->calculatedVsActual($from, $to);

        $this->assertSame(450.0, $figures['calculated']);
        $this->assertSame(390.0, $figures['actual']);
        $this->assertSame(-60.0, $figures['impact']);
        $this->assertSame(1, $figures['adjustments']);
        $this->assertSame(-60.0, $figures['adjustment_value']);
        $this->assertSame(1, $figures['lost_tickets']);
        $this->assertSame(3000.0, $figures['lost_fees']);
    }

    public function test_per_operator_figures_count_entries_checkouts_revenue_and_adjustments(): void
    {
        // Ana issues three tickets and closes two.
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 11:00'), 'final_price' => 150, 'calculated_price' => 150, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 13:00'), 'final_price' => 240, 'calculated_price' => 300, 'exit_user_id' => $this->mira->id, 'adjusted_by' => $this->mira->id, 'adjustment_reason' => 'Regular']);
        $this->ticket(['status' => 'active']);

        [$from, $to] = $this->june();
        $rows = collect($this->stats->perUser($from, $to))->keyBy('user');

        $this->assertSame(3, $rows[$this->ana->name]['issued'] ?? null, 'Ana entered three tickets in the period');
        $this->assertSame(1, $rows[$this->ana->name]['checkouts']);
        $this->assertSame(150.0, $rows[$this->ana->name]['revenue']);
        $this->assertSame(0, $rows[$this->ana->name]['adjustments']);

        $this->assertSame(1, $rows[$this->mira->name]['checkouts']);
        $this->assertSame(240.0, $rows[$this->mira->name]['revenue']);
        $this->assertSame(1, $rows[$this->mira->name]['adjustments']);
        $this->assertSame(-60.0, $rows[$this->mira->name]['adjustment_value']);
    }

    public function test_average_stay_uses_paid_tickets_only(): void
    {
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 11:00'), 'duration_minutes' => 60, 'final_price' => 150, 'calculated_price' => 150, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['status' => 'paid', 'exited_at' => $this->at('2026-06-02 14:00'), 'duration_minutes' => 240, 'final_price' => 600, 'calculated_price' => 600, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['status' => 'lost', 'exited_at' => $this->at('2026-06-03 09:00'), 'duration_minutes' => 9999, 'final_price' => 3000, 'calculated_price' => 300, 'exit_user_id' => $this->ana->id]);

        [$from, $to] = $this->june();

        $this->assertSame(150.0, $this->stats->averageStayMinutes($from, $to));
    }

    public function test_heatmap_samples_occupancy_on_the_hour_by_weekday(): void
    {
        // Tuesday 2 June 2026. Car 10:00-12:00 (1 spot), motorbike 11:00-13:00 (0.5 spot).
        $this->ticket(['entered_at' => $this->at('2026-06-02 10:00'), 'status' => 'paid', 'exited_at' => $this->at('2026-06-02 12:00'), 'final_price' => 300, 'calculated_price' => 300, 'exit_user_id' => $this->ana->id]);
        $this->ticket(['type' => $this->motorbike, 'entered_at' => $this->at('2026-06-02 11:00'), 'status' => 'paid', 'exited_at' => $this->at('2026-06-02 13:00'), 'final_price' => 100, 'calculated_price' => 100, 'exit_user_id' => $this->ana->id]);

        $heatmap = $this->stats->occupancyHeatmap($this->at('2026-06-02'), $this->at('2026-06-02 23:59'));
        $tuesday = $heatmap['cells'][2];

        $this->assertSame(0.0, $tuesday[9], 'Nothing parked at 09:00');

        $this->assertSame(1.0, $tuesday[10], 'Car alone at 10:00');
        $this->assertSame(1.5, $tuesday[11], 'Car + motorbike at 11:00');
        $this->assertSame(0.5, $tuesday[12], 'Car left at 12:00, motorbike still in');
        $this->assertSame(0.0, $tuesday[13], 'Both gone by 13:00');
        $this->assertSame(1.5, $heatmap['max']);
    }

    public function test_heatmap_ignores_hours_that_have_not_happened_yet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 10:30', 'Europe/Tirane'));

        $heatmap = $this->stats->occupancyHeatmap($this->at('2026-06-02'), $this->at('2026-06-02 23:59'));

        $this->assertNotNull($heatmap['cells'][2][10]);
        $this->assertNull($heatmap['cells'][2][11]);
    }

    public function test_live_occupancy_counts_active_vehicles_and_free_spots(): void
    {
        $this->ticket(['status' => 'active']);
        $this->ticket(['type' => $this->motorbike, 'status' => 'active']);

        $live = $this->stats->liveOccupancy();

        $this->assertSame(1.5, $live['occupied_spots']);
        $this->assertSame(10.0, $live['total_spots']);
        $this->assertSame(8.5, $live['free_spots']);
        $this->assertSame(['Car', 'Motorbike', 'Van'], array_column($live['types'], 'name'));
        $this->assertSame(1, $live['types'][0]['vehicles']);
    }

    public function test_period_presets_start_on_the_right_day(): void
    {
        $now = CarbonImmutable::parse('2026-06-10 12:00', 'Europe/Tirane'); // a Wednesday

        $this->assertSame('2026-06-10', DateRange::fromFilters(['preset' => 'today'], $now)->from->toDateString());
        $this->assertSame('2026-06-08', DateRange::fromFilters(['preset' => 'week'], $now)->from->toDateString(), 'Weeks start on Monday');
        $this->assertSame('2026-06-01', DateRange::fromFilters(['preset' => 'month'], $now)->from->toDateString());
    }

    public function test_custom_range_swaps_reversed_dates_and_falls_back_on_bad_input(): void
    {
        $now = CarbonImmutable::parse('2026-06-10 12:00', 'Europe/Tirane');

        $swapped = DateRange::fromFilters(['preset' => 'custom', 'from' => '2026-06-20', 'to' => '2026-06-05'], $now);
        $this->assertSame('2026-06-05', $swapped->from->toDateString());
        $this->assertSame('2026-06-20', $swapped->to->toDateString());

        $bad = DateRange::fromFilters(['preset' => 'custom', 'from' => 'not a date'], $now);
        $this->assertSame('month', $bad->preset);
    }
}
