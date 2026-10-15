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
 *  7. A tariff with any duration_tiers switches rules 3-4 off entirely for every day-period: instead
 *     of billing units at price_per_unit, each day-period's own elapsed minutes (within that
 *     period, same rolling-day basis as everything else here) picks a single flat step price —
 *     e.g. 100 up to 2h, 200 up to 10h, a flat day-rate beyond that. billing_unit_minutes,
 *     price_per_unit, first_unit_price and time_bands are all ignored when tiers are set; grace,
 *     daily_max and long_stay still apply the same way on top, since those already work per
 *     day-period regardless of how its gross price was computed.
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

        $dailyMaxCents = $snapshot['daily_max'] !== null ? $this->toCents($snapshot['daily_max']) : null;
        $timezone = $snapshot['timezone'];
        $durationTiers = $snapshot['duration_tiers'] ?? [];

        // Rolling 24h day-periods from entry, not calendar dates. Whole 24h blocks, plus one more
        // only if the leftover time is more than half a day — see rule 5 above.
        $minutesPerDay = 24 * 60;
        $wholeDays = intdiv($durationMinutes, $minutesPerDay);
        $leftoverMinutes = $durationMinutes % $minutesPerDay;
        $dayCount = max(1, $wholeDays + ($leftoverMinutes > intdiv($minutesPerDay, 2) ? 1 : 0));

        /** @var array<int, array{units: int, gross: int, lines: array<string, array{label: string, units: int, unit_price_cents: int, amount_cents: int}>}> $perDay */
        $perDay = [];
        $unitsBilled = 0;

        if ($durationTiers !== []) {
            // Rule 7: one flat lookup per day-period instead of counting billing units at all.
            for ($dayIndex = 0; $dayIndex < $dayCount; $dayIndex++) {
                $dayStart = $dayIndex * $minutesPerDay;
                $dayEnd = $dayIndex < $dayCount - 1 ? $dayStart + $minutesPerDay : $durationMinutes;
                $elapsedInPeriod = $dayEnd - $dayStart;

                [$label, $tierCents] = $this->matchingTier($durationTiers, $elapsedInPeriod);

                $perDay[$dayIndex] = [
                    'units' => 1,
                    'gross' => $tierCents,
                    'lines' => [$label => ['label' => $label, 'units' => 1, 'unit_price_cents' => $tierCents, 'amount_cents' => $tierCents]],
                ];
                $unitsBilled++;
            }
        } else {
            $unitMinutes = (int) $snapshot['billing_unit_minutes'];
            $unitsBilled = intdiv($durationMinutes + $unitMinutes - 1, $unitMinutes);

            $standardCents = $this->toCents($snapshot['price_per_unit']);
            $firstUnitCents = $snapshot['first_unit_price'] !== null ? $this->toCents($snapshot['first_unit_price']) : null;

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
     * The flat tier a day-period's own elapsed minutes falls into — the first tier (sorted by
     * up_to_minutes, nulls/"and beyond" last) whose cutoff is at or past $elapsedMinutes.
     *
     * @param  list<array{label: ?string, up_to_minutes: ?int, price: float|string}>  $tiers
     * @return array{0: string, 1: int} [label, price in cents]
     */
    private function matchingTier(array $tiers, int $elapsedMinutes): array
    {
        $sorted = $tiers;
        usort($sorted, fn (array $a, array $b) => ($a['up_to_minutes'] ?? PHP_INT_MAX) <=> ($b['up_to_minutes'] ?? PHP_INT_MAX));

        foreach ($sorted as $tier) {
            if ($tier['up_to_minutes'] === null || $elapsedMinutes <= $tier['up_to_minutes']) {
                $label = $tier['label'] ?: ($tier['up_to_minutes'] !== null ? 'Deri në '.$tier['up_to_minutes'].' min' : 'Mbi pragun e fundit');

                return [$label, $this->toCents($tier['price'])];
            }
        }

        // Unreachable in practice (the loop above always matches the null/open tier if one
        // exists), but every tariff using tiers is expected to end with one — if it doesn't,
        // fall back to the last tier's price rather than silently charging nothing.
        $last = end($sorted);

        return [$last['label'] ?: 'Standard', $this->toCents($last['price'])];
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
