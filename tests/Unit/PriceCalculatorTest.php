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
            'long_stay_threshold_days' => null,
            'long_stay_daily_price' => null,
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
    public function a_stay_crossing_midnight_is_still_one_rolling_day_under_the_half_day_threshold(): void
    {
        // 22:00 Mon -> 10:00 Tue is 12 hours, crossing a calendar date but not more than half a day
        // past entry, so it's one day-period, capped once — not once per calendar date.
        $snapshot = $this->snapshot(['price_per_unit' => 100, 'daily_max' => 500]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 22:00'), $this->local('2026-06-02 10:00'));

        $this->assertCount(1, $result->days);
        $this->assertSame(500.0, $result->total());
        $this->assertSame('2026-06-01', $result->days[0]['date']);
        $this->assertSame(120000, $result->days[0]['gross_cents']); // 12 units * 100
        $this->assertSame(50000, $result->days[0]['charged_cents']);
    }

    // ----- Rolling day-periods (from entry, half-day rounding) --------------

    #[Test]
    public function the_daily_cap_is_measured_from_entry_time_not_calendar_midnight(): void
    {
        // A real-world case: entering mid-afternoon and leaving mid-afternoon two days later is
        // "2 days" in plain speech (48h17m), even though it touches 3 calendar dates. The 17
        // extra minutes don't earn a 3rd day (not more than half a day), so this is billed as
        // 2 day-periods, not 3.
        $snapshot = $this->snapshot(['price_per_unit' => 150, 'daily_max' => 1500, 'time_bands' => [$this->nightBand(200)]]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-10-07 14:06'), $this->local('2026-10-09 14:23'));

        $this->assertCount(2, $result->days);
        $this->assertSame(3000.0, $result->total());
        $this->assertTrue($result->days[0]['daily_cap_applied']);
        $this->assertTrue($result->days[1]['daily_cap_applied']);
    }

    #[Test]
    public function leftover_time_of_half_a_day_or_less_does_not_add_another_day(): void
    {
        // 1 day + 6 hours = 30 hours. 6 hours is not more than half a day, so this is 1 day-period.
        $snapshot = $this->snapshot(['price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 00:00'), $this->local('2026-06-02 06:00'));

        $this->assertSame(30, $result->unitsBilled);
        $this->assertCount(1, $result->days);
        $this->assertSame(30, $result->days[0]['units']);
    }

    #[Test]
    public function leftover_time_of_more_than_half_a_day_adds_another_day(): void
    {
        // 1 day + 13 hours = 37 hours. 13 hours is more than half a day, so this rounds up to 2 days.
        $snapshot = $this->snapshot(['price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 00:00'), $this->local('2026-06-02 13:00'));

        $this->assertSame(37, $result->unitsBilled);
        $this->assertCount(2, $result->days);
        $this->assertSame(24, $result->days[0]['units']);
        $this->assertSame(13, $result->days[1]['units']);
    }

    // ----- Multi-day stays --------------------------------------------------

    #[Test]
    public function multi_day_stay_without_daily_max_is_billed_per_unit(): void
    {
        // 50 hours = 2 days + 2 leftover hours (not more than half a day): 2 day-periods.
        $snapshot = $this->snapshot(['price_per_unit' => 100]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 00:00'), $this->local('2026-06-03 02:00'));

        $this->assertSame(50, $result->unitsBilled);
        $this->assertSame(5000.0, $result->total());
        $this->assertCount(2, $result->days);
    }

    // ----- Long-stay (multi-day) pricing -------------------------------------

    #[Test]
    public function long_stay_rate_does_not_apply_below_the_threshold(): void
    {
        // Exactly 2 rolling days (48h), threshold is 3: still billed at daily_max per day.
        $snapshot = $this->snapshot([
            'price_per_unit' => 100,
            'daily_max' => 500,
            'long_stay_threshold_days' => 3,
            'long_stay_daily_price' => 50,
        ]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 08:00'), $this->local('2026-06-03 08:00'));

        $this->assertCount(2, $result->days);
        $this->assertFalse($result->days[0]['long_stay_applied']);
        $this->assertSame(1000.0, $result->total());
    }

    #[Test]
    public function long_stay_rate_replaces_the_whole_stay_once_the_threshold_is_reached(): void
    {
        // Exactly 3 rolling days (72h), threshold is 3: every day billed at the flat long-stay
        // rate (50), not the hourly total or the daily_max (500).
        $snapshot = $this->snapshot([
            'price_per_unit' => 100,
            'daily_max' => 500,
            'long_stay_threshold_days' => 3,
            'long_stay_daily_price' => 50,
        ]);

        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 08:00'), $this->local('2026-06-04 08:00'));

        $this->assertCount(3, $result->days);
        $this->assertSame(150.0, $result->total());

        foreach ($result->days as $day) {
            $this->assertTrue($day['long_stay_applied']);
            $this->assertFalse($day['daily_cap_applied']);
            $this->assertSame(5000, $day['charged_cents']);
        }
    }

    #[Test]
    public function long_stay_rate_is_ignored_when_either_setting_is_missing(): void
    {
        $withoutPrice = $this->snapshot(['price_per_unit' => 100, 'long_stay_threshold_days' => 1, 'long_stay_daily_price' => null]);
        $withoutThreshold = $this->snapshot(['price_per_unit' => 100, 'long_stay_threshold_days' => null, 'long_stay_daily_price' => 50]);

        $resultA = $this->calculator->calculate($withoutPrice, $this->local('2026-06-01 08:00'), $this->local('2026-06-01 10:00'));
        $resultB = $this->calculator->calculate($withoutThreshold, $this->local('2026-06-01 08:00'), $this->local('2026-06-01 10:00'));

        $this->assertSame(200.0, $resultA->total());
        $this->assertSame(200.0, $resultB->total());
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

    // ----- Duration tiers -----------------------------------------------------

    /** @return list<array{label: ?string, up_to_minutes: ?int, price: float}> */
    private function clientTiers(): array
    {
        // The exact scenario a client asked for: 100 from 5 min to 2h, 200 from 2h to 10h,
        // then a flat 500 "full day" rate beyond that.
        return [
            ['label' => null, 'up_to_minutes' => 120, 'price' => 100.0],
            ['label' => null, 'up_to_minutes' => 600, 'price' => 200.0],
            ['label' => 'Dita e plotë', 'up_to_minutes' => null, 'price' => 500.0],
        ];
    }

    #[Test]
    public function duration_tiers_price_the_first_step(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 5, 'duration_tiers' => $this->clientTiers()]);

        $this->assertSame(100.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:06'));
        $this->assertSame(100.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 12:00'));
    }

    #[Test]
    public function duration_tiers_still_honour_grace(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 5, 'duration_tiers' => $this->clientTiers()]);

        $this->assertSame(0.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 10:04'));
    }

    #[Test]
    public function duration_tiers_price_the_second_step(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 5, 'duration_tiers' => $this->clientTiers()]);

        // One minute past the first tier's cutoff, and right at the second tier's own cutoff —
        // both still a flat 200, not a gradually climbing per-unit price.
        $this->assertSame(200.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 12:01'));
        $this->assertSame(200.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 20:00'));
    }

    #[Test]
    public function duration_tiers_fall_through_to_the_open_ended_tier_past_every_cutoff(): void
    {
        $snapshot = $this->snapshot(['grace_minutes' => 5, 'duration_tiers' => $this->clientTiers()]);

        $this->assertSame(500.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 20:01'));
    }

    #[Test]
    public function duration_tiers_ignore_billing_unit_and_price_per_unit_entirely(): void
    {
        // A huge price_per_unit that would dominate if tiers weren't actually replacing it.
        $snapshot = $this->snapshot([
            'billing_unit_minutes' => 1,
            'price_per_unit' => 100000,
            'first_unit_price' => 999999,
            'grace_minutes' => 5,
            'duration_tiers' => $this->clientTiers(),
        ]);

        $this->assertSame(200.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 15:00'));
    }

    #[Test]
    public function each_day_period_picks_its_own_tier_independently(): void
    {
        // Day 1 runs the full 1440 minutes (past every cutoff -> the open tier), day 2 only
        // carries the 800-minute leftover (past 600 but at/under 900 -> this tariff's own
        // second tier) — each day-period is looked up on its own elapsed minutes, not the
        // stay's cumulative total.
        $snapshot = $this->snapshot([
            'grace_minutes' => 0,
            'duration_tiers' => [
                ['label' => 'A', 'up_to_minutes' => 900, 'price' => 50.0],
                ['label' => 'B', 'up_to_minutes' => null, 'price' => 300.0],
            ],
        ]);

        // 1440 + 800 = 2240 minutes, leftover (800) > half a day so this is 2 day-periods.
        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 00:00'), $this->local('2026-06-02 13:20'));

        $this->assertSame(350.0, $result->total());
        $this->assertCount(2, $result->days);
        $this->assertSame(300.0, $result->days[0]['charged_cents'] / 100.0, 'Day 1: 1440 elapsed minutes falls past every cutoff');
        $this->assertSame(50.0, $result->days[1]['charged_cents'] / 100.0, 'Day 2: only its own 800-minute leftover, which fits tier A');
    }

    #[Test]
    public function daily_max_still_caps_a_tier_priced_day(): void
    {
        $snapshot = $this->snapshot([
            'grace_minutes' => 5,
            'daily_max' => 150,
            'duration_tiers' => $this->clientTiers(),
        ]);

        // The second tier alone (200) would exceed the daily cap (150).
        $this->assertSame(150.0, $this->price($snapshot, '2026-06-01 10:00', '2026-06-01 15:00'));
        $this->assertTrue($this->calculator->calculate($snapshot, $this->local('2026-06-01 10:00'), $this->local('2026-06-01 15:00'))->days[0]['daily_cap_applied']);
    }

    #[Test]
    public function long_stay_rate_still_overrides_tier_pricing(): void
    {
        $snapshot = $this->snapshot([
            'grace_minutes' => 5,
            'duration_tiers' => $this->clientTiers(),
            'long_stay_threshold_days' => 2,
            'long_stay_daily_price' => 1000,
        ]);

        // 3 full days: every day-period would otherwise hit the open tier (500 each = 1500),
        // but the long-stay rate takes over once the threshold is met.
        $result = $this->calculator->calculate($snapshot, $this->local('2026-06-01 00:00'), $this->local('2026-06-04 00:00'));

        $this->assertSame(3000.0, $result->total());
        foreach ($result->days as $day) {
            $this->assertTrue($day['long_stay_applied']);
        }
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
