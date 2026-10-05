<?php

namespace App\Services;

/**
 * Immutable result of a price calculation. Money is stored as integer cents internally.
 */
final readonly class PriceResult
{
    /**
     * @param  list<array{date: string, units: int, gross_cents: int, charged_cents: int, daily_cap_applied: bool, lines: list<array{label: string, units: int, unit_price_cents: int, amount_cents: int}>}>  $days
     */
    public function __construct(
        public int $durationMinutes,
        public int $unitsBilled,
        public int $totalCents,
        public bool $graceApplied,
        public array $days,
    ) {}

    public function total(): float
    {
        return $this->totalCents / 100;
    }

    public function toArray(): array
    {
        return [
            'duration_minutes' => $this->durationMinutes,
            'units_billed' => $this->unitsBilled,
            'total' => $this->total(),
            'grace_applied' => $this->graceApplied,
            'days' => array_map(fn (array $day) => [
                'date' => $day['date'],
                'units' => $day['units'],
                'gross' => $day['gross_cents'] / 100,
                'charged' => $day['charged_cents'] / 100,
                'daily_cap_applied' => $day['daily_cap_applied'],
                'lines' => array_map(fn (array $line) => [
                    'label' => $line['label'],
                    'units' => $line['units'],
                    'unit_price' => $line['unit_price_cents'] / 100,
                    'amount' => $line['amount_cents'] / 100,
                ], $day['lines']),
            ], $this->days),
        ];
    }
}
