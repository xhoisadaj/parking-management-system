<?php

namespace App\Services\Statistics;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * A reporting period in the parking's timezone. Presets are relative to now.
 * Both ends are inclusive and cover whole days.
 */
final readonly class DateRange
{
    public const PRESETS = ['today', 'week', 'month', 'custom'];

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $preset,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters  dashboard filter state: preset, from, to
     */
    public static function fromFilters(?array $filters, ?CarbonImmutable $now = null): self
    {
        $tz = Setting::current()->timezone;
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $preset = $filters['preset'] ?? 'month';

        if (! in_array($preset, self::PRESETS, true)) {
            $preset = 'month';
        }

        return match ($preset) {
            'today' => new self($now->startOfDay(), $now->endOfDay(), $preset),
            'week' => new self($now->startOfWeek()->startOfDay(), $now->endOfDay(), $preset),
            'custom' => self::custom($filters, $now, $tz),
            default => new self($now->startOfMonth()->startOfDay(), $now->endOfDay(), 'month'),
        };
    }

    /** Explicit range, for example from the export link. */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, string $preset = 'custom'): self
    {
        return new self($from->startOfDay(), $to->endOfDay(), $preset);
    }

    private static function custom(?array $filters, CarbonImmutable $now, string $tz): self
    {
        try {
            $from = CarbonImmutable::parse($filters['from'] ?? '', $tz)->startOfDay();
            $to = CarbonImmutable::parse($filters['to'] ?? '', $tz)->endOfDay();
        } catch (\Throwable) {
            return new self($now->startOfMonth()->startOfDay(), $now->endOfDay(), 'month');
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return new self($from, $to, 'custom');
    }

    public function label(): string
    {
        return match ($this->preset) {
            'today' => 'Sot',
            'week' => 'Kjo javë',
            'month' => 'Ky muaj',
            default => $this->from->format('d M Y').' – '.$this->to->format('d M Y'),
        };
    }
}
