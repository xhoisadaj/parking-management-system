<?php

namespace Tests\Unit;

use App\Services\PriceCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'tariff_id' => 1,
            'vehicle_type_id' => 1,
            'name' => 'Standard',
            'timezone' => 'Europe/Tirane',
            'billing_unit_minutes' => 60,
            'price_per_unit' => 100.0,
            'first_unit_price' => null,
            'grace_minutes' => 0,
            'daily_max' => null,
            'rounding' => 'up',
            'time_bands' => [],
        ], $overrides);
    }

    private function local(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'Europe/Tirane');
    }

    private function price(array $snapshot, string $entry, string $exit): float
    {
        return $this->calculator->calculate($snapshot, $this->local($entry), $this->local($exit))->total();
    }

    // ----- Grace period -----------------------------------------------------

    #[Test]
    public function stay_within_grace_period_is_free(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 10]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 10:00'), $this->local('2026-06-01 10:08'));

        $this->assertSame(0.0, $result->total());
        $this->assertTrue($result->graceApplied);
        $this->assertSame(0, $result->unitsBilled);
    }

    #[Test]
    public function stay_exactly_equal_to_grace_is_free(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 10]);

        $this->assertSame(0.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:10'));
    }

    #[Test]
    public function stay_one_minute_past_grace_is_charged_for_the_whole_stay(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 10]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 10:00'), $this->local('2026-06-01 10:11'));

        $this->assertFalse($result->graceApplied);
        $this->assertSame(1, $result->unitsBilled);
        $this->assertSame(100.0, $result->total());
    }

    #[Test]
    public function zero_length_stay_is_free(): void
    {
        $this->assertSame(0.0, $this->price($this->snapshot(), '2026-06-01 10:00', '2026-06-01 10:00'));
    }

    // ----- Rounding (always up to the next unit) ----------------------------

    #[Test]
    public function partial_unit_is_charged_as_a_full_unit(): void
    {
        $snapshot = $this->snapshot(['billing_unit_minutes' => 60, 'price_per_unit' => 100]);

        $this->assertSame(200.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 11:01'));
    }

    #[Test]
    public function exact_unit_multiple_is_not_rounded_up_further(): void
    {
        $snapshot = $this->snapshot(['billing_unit_minutes' => 15, 'price_per_unit' => 25]);

        $this->assertSame(25.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:15'));
        $this->assertSame(50.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:16'));
    }

    #[Test]
    public function one_minute_stay_with_15_minute_units_is_one_unit(): void
    {
        $snapshot = $this->snapshot(['billing_unit_minutes' => 15, 'price_per_unit' => 25]);

        $this->assertSame(25.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:01'));
    }

    #[Test]
    public function seconds_count_towards_the_next_whole_minute(): void
    {
        $snapshot = $this->snapshot(['billing_unit_minutes' => 60, 'price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 10:00:00'), $this->local('2026-06-01 11:00:01'));

        $this->assertSame(61, $result->durationMinutes);
        $this->assertSame(2, $result->unitsBilled);
        $this->assertSame(200.0, $result->total());
    }

    // ----- First-unit price -------------------------------------------------

    #[Test]
    public function first_unit_can_have_its_own_price(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'first_unit_price' => 200]);

        // 150 minutes = 3 hourly units: 200 (first) + 100 + 100
        $this->assertSame(400.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 12:30'));
    }

    #[Test]
    public function single_unit_stay_uses_only_the_first_unit_price(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'first_unit_price' => 200]);

        $this->assertSame(200.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:30'));
    }

    // ----- Daily maximum ----------------------------------------------------

    #[Test]
    public function daily_maximum_caps_a_long_single_day_stay(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'daily_max' => 500]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 08:00'), $this->local('2026-06-01 18:00'));

        $this->assertSame(500.0, $result->total());
        $this->assertCount(1, $result->days);
        $this->assertTrue($result->days[0]['daily_cap_applied']);
        $this->assertSame(100000, $result->days[0]['gross_cents']);
        $this->assertSame(50000, $result->days[0]['charged_cents']);
    }

    #[Test]
    public function daily_maximum_does_not_apply_when_under_the_cap(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'daily_max' => 500]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 08:00'), $this->local('2026-06-01 11:00'));

        $this->assertSame(300.0, $result->total());
        $this->assertFalse($result->days[0]['daily_cap_applied']);
    }

    #[Test]
    public function daily_maximum_applies_per_calendar_day_across_midnight(): void
    {
        // 22:00 Mon -> 10:00 Tue. Mon: 2 units (200, under cap). Tue: 10 units (1000, capped to 500).
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'daily_max' => 500]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 22:00'), $this->local('2026-06-02 10:00'));

        $this->assertSame(700.0, $result->total());
        $this->assertCount(2, $result->days);
        $this->assertSame('2026-06-01', $result->days[0]['date']);
        $this->assertSame(20000, $result->days[0]['charged_cents']);
        $this->assertSame('2026-06-02', $result->days[1]['date']);
        $this->assertSame(50000, $result->days[1]['charged_cents']);
    }

    // ----- Multi-day stays --------------------------------------------------

    #[Test]
    public function multi_day_stay_without_daily_max_is_billed_per_unit(): void
    {
        // 50 hours, hourly units, no cap
        $snapshot = $this->snapshot(['price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 00:00'), $this->local('2026-06-03 02:00'));

        $this->assertSame(50, $result->unitsBilled);
        $this->assertSame(5000.0, $result->total());
        $this->assertCount(3, $result->days);
    }

    // ----- Time bands -------------------------------------------------------

    private function nightBand(float $price = 150.0): array
    {
        return [
            'label' => 'Night',
            'starts_at' => '22:00',
            'ends_at' => '06:00',
            'price_per_unit' => $price,
        ];
    }

    #[Test]
    public function band_crossing_midnight_applies_to_units_starting_before_midnight(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'time_bands' => [$this->nightBand()]]);

        // 21:00 (standard, 100) + 22:00 (night, 150)
        $this->assertSame(250.0, $this->price($snapshot, '2026-06-01 21:00', '2026-06-01 23:00'));
    }

    #[Test]
    public function band_crossing_midnight_applies_to_units_starting_after_midnight(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'time_bands' => [$this->nightBand()]]);

        // 05:30 (night, 150) + 06:30 (standard, 100)
        $this->assertSame(250.0, $this->price($snapshot, '2026-06-01 05:30', '2026-06-01 07:00'));
    }

    #[Test]
    public function band_end_time_is_exclusive(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'time_bands' => [$this->nightBand()]]);

        // Unit starting exactly at 06:00 is standard.
        $this->assertSame(100.0, $this->price($snapshot, '2026-06-01 06:00', '2026-06-01 07:00'));
    }

    #[Test]
    public function band_start_time_is_inclusive(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'time_bands' => [$this->nightBand()]]);

        // Unit starting exactly at 22:00 is night.
        $this->assertSame(150.0, $this->price($snapshot, '2026-06-01 22:00', '2026-06-01 23:00'));
    }

    #[Test]
    public function same_day_band_applies_only_inside_its_window(): void
    {
        $snapshot = $this->snapshot([
            'price_per_unit' => 100,
            'time_bands' => [['label' => 'Peak', 'starts_at' => '09:00', 'ends_at' => '17:00', 'price_per_unit' => 80]],
        ]);

        // 16:00 (peak, 80) + 17:00 (standard, 100)
        $this->assertSame(180.0, $this->price($snapshot, '2026-06-01 16:00', '2026-06-01 18:00'));
    }

    #[Test]
    public function band_breakdown_is_reported_as_separate_lines(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'time_bands' => [$this->nightBand()]]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 21:00'), $this->local('2026-06-01 23:00'));
        $lines = $result->days[0]['lines'];

        $this->assertCount(2, $lines);
        $this->assertSame('Standard', $lines[0]['label']);
        $this->assertSame(10000, $lines[0]['unit_price_cents']);
        $this->assertSame('Night', $lines[1]['label']);
        $this->assertSame(15000, $lines[1]['unit_price_cents']);
    }

    #[Test]
    public function first_unit_price_overrides_a_time_band_on_the_first_unit(): void
    {
        $snapshot = $this->snapshot([
            'price_per_unit' => 100,
            'first_unit_price' => 120,
            'time_bands' => [$this->nightBand()],
        ]);

        // 22:00 first unit (120 override, not 150) + 23:00 (night, 150)
        $this->assertSame(270.0, $this->price($snapshot, '2026-06-01 22:00', '2026-06-02 00:00'));
    }

    #[Test]
    public function first_matching_band_by_position_wins(): void
    {
        $snapshot = $this->snapshot([
            'price_per_unit' => 100,
            'time_bands' => [
                ['label' => 'Early', 'starts_at' => '09:00', 'ends_at' => '12:00', 'price_per_unit' => 70],
                ['label' => 'Wide', 'starts_at' => '08:00', 'ends_at' => '18:00', 'price_per_unit' => 90],
            ],
        ]);

        $this->assertSame(70.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 11:00'));
    }

    // ----- DST (Europe/Tirane: CET +01:00 / CEST +02:00) ---------------------

    #[Test]
    public function spring_forward_night_bills_elapsed_time_not_wall_clock_time(): void
    {
        // 2026-03-29: clocks jump 02:00 CET -> 03:00 CEST.
        // 01:30 CET (00:30 UTC) to 04:30 CEST (02:30 UTC) is 2 real hours, not 3 wall-clock hours.
        $snapshot = $this->snapshot(['price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-03-29 01:30'), $this->local('2026-03-29 04:30'));

        $this->assertSame(120, $result->durationMinutes);
        $this->assertSame(2, $result->unitsBilled);
        $this->assertSame(200.0, $result->total());
    }

    #[Test]
    public function fall_back_night_bills_the_extra_hour(): void
    {
        // 2026-10-25: clocks go back 03:00 CEST -> 02:00 CET.
        // 01:30 CEST (23:30 UTC prev day) to 04:30 CET (03:30 UTC) is 4 real hours, not 3 wall-clock hours.
        $snapshot = $this->snapshot(['price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-10-25 01:30'), $this->local('2026-10-25 04:30'));

        $this->assertSame(240, $result->durationMinutes);
        $this->assertSame(4, $result->unitsBilled);
        $this->assertSame(400.0, $result->total());
    }

    #[Test]
    public function night_band_across_spring_forward_uses_local_wall_clock_for_each_unit(): void
    {
        // 21:00 CET (20:00 UTC) to 03:00 CEST (01:00 UTC) = 5 real hours.
        // Unit starts (local): 21:00 standard (100), then 22:00, 23:00, 00:00, 01:00 night (150 each).
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'time_bands' => [$this->nightBand()]]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-03-28 21:00'), $this->local('2026-03-29 03:00'));

        $this->assertSame(5, $result->unitsBilled);
        $this->assertSame(700.0, $result->total());
    }

    // ----- Snapshot is the only source of truth -----------------------------

    #[Test]
    public function an_identical_snapshot_always_produces_the_same_price(): void
    {
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'daily_max' => 500, 'time_bands' => [$this->nightBand()]]);

        $first = $this->calculator->calculate($snapshot, $this->local('2026-06-01 20:00'), $this->local('2026-06-02 09:00'));
        $second = $this->calculator->calculate($snapshot, $this->local('2026-06-01 20:00'), $this->local('2026-06-02 09:00'));

        $this->assertEquals($first, $second);
    }

    // ----- Guard rails ------------------------------------------------------

    #[Test]
    public function exit_before_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate($this->snapshot(), $this->local('2026-06-01 12:00'), $this->local('2026-06-01 11:00'));
    }

    #[Test]
    public function unsupported_rounding_rule_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate($this->snapshot(['rounding' => 'nearest']), $this->local('2026-06-01 10:00'), $this->local('2026-06-01 11:00'));
    }
}
