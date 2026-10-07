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
 *  5. Units are grouped by local calendar date. Each day's total is capped at daily_max when set.
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

        /** @var array<string, array{units: int, gross: int, lines: array<string, array{label: string, units: int, unit_price_cents: int, amount_cents: int}>}> $perDay */
        $perDay = [];

        for ($i = 0; $i < $unitsBilled; $i++) {
            // addMinutes works on the absolute timeline, so DST changes do not shift unit boundaries.
            $unitStart = $entryAt->addMinutes($i * $unitMinutes)->setTimezone($timezone);

            $band = $this->matchingBand($snapshot['time_bands'] ?? [], $unitStart);
            $label = $band !== null ? ($band['label'] ?: $band['starts_at'].'-'.$band['ends_at']) : 'Standard';
            $unitCents = $band !== null ? $this->toCents($band['price_per_unit']) : $standardCents;

            if ($i === 0 && $firstUnitCents !== null) {
                $label = 'Njësia e parë';
                $unitCents = $firstUnitCents;
            }

            $date = $unitStart->toDateString();
            $perDay[$date] ??= ['units' => 0, 'gross' => 0, 'lines' => []];
            $perDay[$date]['units']++;
            $perDay[$date]['gross'] += $unitCents;

            $line = $perDay[$date]['lines'][$label] ?? ['label' => $label, 'units' => 0, 'unit_price_cents' => $unitCents, 'amount_cents' => 0];
            $line['units']++;
            $line['amount_cents'] += $unitCents;
            $perDay[$date]['lines'][$label] = $line;
        }

        $days = [];
        $totalCents = 0;

        foreach ($perDay as $date => $day) {
            $charged = $dailyMaxCents !== null ? min($day['gross'], $dailyMaxCents) : $day['gross'];
            $totalCents += $charged;

            $days[] = [
                'date' => (string) $date,
                'units' => $day['units'],
                'gross_cents' => $day['gross'],
                'charged_cents' => $charged,
                'daily_cap_applied' => $charged < $day['gross'],
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
