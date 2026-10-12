<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Pure pricing logic: a frozen tariff snapshot + entry/exit instants -> price and breakdown.
 *
 * Deliberately framework-free (only Carbon). It never reads the live tariff; the snapshot
 * taken at entry is the single source of truth for a ticket's price.
 *
 * Rules, in order:
 *  1. Elapsed real time is measured in absolute seconds (DST-safe), rounded up to whole minutes.
 *  2. If the duration is within grace_minutes the stay is free.
 *  3. Otherwise the stay is split into billing units starting at entry; a partial last unit is charged in full.
 *  4. Each unit is priced by the time band its start falls into (local wall-clock time), else the standard price.
 *     The first unit uses first_unit_price when set (it overrides any band).
 *  5. "Day" for the daily cap means a rolling 24 hours from the moment the vehicle entered, not a
 *     calendar date. The number of day-periods in the stay is the number of whole 24h blocks,
 *     plus one more only if what's left over is more than half a day (12h) — a 48h17m stay is 2
 *     days (17 minutes doesn't earn a 3rd), a 13h stay is 1 day, a 37h stay is 2 days. Units are
 *     grouped into these day-periods (the last one absorbing whatever time rounding left over)
 *     and each period's total is capped at daily_max when set.
 *  6. If the stay spans at least long_stay_threshold_days such day-periods, the whole stay is
 *     re-priced: every day-period is charged the flat long_stay_daily_price instead of its hourly
 *     total/daily_max, for lots (e.g. airport parking) that need a separate rate for multi-day stays.
 */
final class PriceCalculator
{
    public function calculate(array $snapshot, DateTimeInterface $entry, DateTimeInterface $exit): PriceResult
    {
        $entryAt = CarbonImmutable::instance($entry);
        $exitAt = CarbonImmutable::instance($exit);

        $elapsedSeconds = $exitAt->getTimestamp() - $entryAt->getTimestamp();
        if ($elapsedSeconds < 0) {
            throw new InvalidArgumentException('Ora e daljes është para orës së hyrjes.');
        }

        $durationMinutes = intdiv($elapsedSeconds + 59, 60);

        if ($durationMinutes <= (int) $snapshot['grace_minutes']) {
            return new PriceResult(
                durationMinutes: $durationMinutes,
                unitsBilled: 0,
                totalCents: 0,
                graceApplied: true,
                days: [],
            );
        }

        if (($snapshot['rounding'] ?? 'up') !== 'up') {
            throw new InvalidArgumentException('Rregull rrumbullakimi i pambështetur: '.$snapshot['rounding']);
        }

        $unitMinutes = (int) $snapshot['billing_unit_minutes'];
        $unitsBilled = intdiv($durationMinutes + $unitMinutes - 1, $unitMinutes);

        $standardCents = $this->toCents($snapshot['price_per_unit']);
        $firstUnitCents = $snapshot['first_unit_price'] !== null ? $this->toCents($snapshot['first_unit_price']) : null;
        $dailyMaxCents = $snapshot['daily_max'] !== null ? $this->toCents($snapshot['daily_max']) : null;
        $timezone = $snapshot['timezone'];

        // Rolling 24h day-periods from entry, not calendar dates. Whole 24h blocks, plus one more
        // only if the leftover time is more than half a day — see rule 5 above.
        $minutesPerDay = 24 * 60;
        $wholeDays = intdiv($durationMinutes, $minutesPerDay);
        $leftoverMinutes = $durationMinutes % $minutesPerDay;
        $dayCount = max(1, $wholeDays + ($leftoverMinutes > intdiv($minutesPerDay, 2) ? 1 : 0));

        /** @var array<int, array{units: int, gross: int, lines: array<string, array{label: string, units: int, unit_price_cents: int, amount_cents: int}>}> $perDay */
        $perDay = [];

        for ($i = 0; $i < $unitsBilled; $i++) {
            $offsetMinutes = $i * $unitMinutes;
            // addMinutes works on the absolute timeline, so DST changes do not shift unit boundaries.
            $unitStart = $entryAt->addMinutes($offsetMinutes)->setTimezone($timezone);

            $band = $this->matchingBand($snapshot['time_bands'] ?? [], $unitStart);
            $label = $band !== null ? ($band['label'] ?: $band['starts_at'].'-'.$band['ends_at']) : 'Standard';
            $unitCents = $band !== null ? $this->toCents($band['price_per_unit']) : $standardCents;

            if ($i === 0 && $firstUnitCents !== null) {
                $label = 'Njësia e parë';
                $unitCents = $firstUnitCents;
            }

            // Which day-period this unit falls into, by elapsed time from entry — the last period
            // absorbs anything past it (that's the "leftover rounds down" half of rule 5).
            $dayIndex = min(intdiv($offsetMinutes, $minutesPerDay), $dayCount - 1);

            $perDay[$dayIndex] ??= ['units' => 0, 'gross' => 0, 'lines' => []];
            $perDay[$dayIndex]['units']++;
            $perDay[$dayIndex]['gross'] += $unitCents;

            $line = $perDay[$dayIndex]['lines'][$label] ?? ['label' => $label, 'units' => 0, 'unit_price_cents' => $unitCents, 'amount_cents' => 0];
            $line['units']++;
            $line['amount_cents'] += $unitCents;
            $perDay[$dayIndex]['lines'][$label] = $line;
        }

        $longStayThresholdDays = $snapshot['long_stay_threshold_days'] ?? null;
        $longStayDailyCents = isset($snapshot['long_stay_daily_price']) && $snapshot['long_stay_daily_price'] !== null
            ? $this->toCents($snapshot['long_stay_daily_price'])
            : null;
        $longStayApplied = $longStayThresholdDays !== null
            && $longStayDailyCents !== null
            && $dayCount >= $longStayThresholdDays;

        $days = [];
        $totalCents = 0;

        foreach ($perDay as $dayIndex => $day) {
            $charged = match (true) {
                $longStayApplied => $longStayDailyCents,
                $dailyMaxCents !== null => min($day['gross'], $dailyMaxCents),
                default => $day['gross'],
            };
            $totalCents += $charged;

            // Label the period by the local date it starts on — e.g. day 2 of a 07→09 Oct stay
            // starts on the 8th, even though it runs into the 9th.
            $periodStart = $entryAt->addMinutes($dayIndex * $minutesPerDay)->setTimezone($timezone);

            $days[] = [
                'date' => $periodStart->toDateString(),
                'units' => $day['units'],
                'gross_cents' => $day['gross'],
                'charged_cents' => $charged,
                'daily_cap_applied' => ! $longStayApplied && $charged < $day['gross'],
                'long_stay_applied' => $longStayApplied,
                'lines' => array_values($day['lines']),
            ];
        }

        return new PriceResult(
            durationMinutes: $durationMinutes,
            unitsBilled: $unitsBilled,
            totalCents: $totalCents,
            graceApplied: false,
            days: $days,
        );
    }

    /**
     * @param  list<array{label: ?string, starts_at: string, ends_at: string, price_per_unit: float|string}>  $bands
     */
    private function matchingBand(array $bands, CarbonImmutable $localTime): ?array
    {
        $now = $localTime->format('H:i');

        foreach ($bands as $band) {
            $start = substr((string) $band['starts_at'], 0, 5);
            $end = substr((string) $band['ends_at'], 0, 5);

            $inBand = match (true) {
                $start === $end => true,                              // whole-day band
                $start < $end => $now >= $start && $now < $end,       // same-day band, e.g. 09:00-17:00
                default => $now >= $start || $now < $end,             // crosses midnight, e.g. 22:00-06:00
            };

            if ($inBand) {
                return $band;
            }
        }

        return null;
    }

    private function toCents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
