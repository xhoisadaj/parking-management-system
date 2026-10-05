<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Display formatting for operator screens and printed tickets.
 */
final class Format
{
    public static function money(float|string|null $amount, ?string $currency = null): string
    {
        $currency ??= Setting::current()->currency;

        return $amount === null
            ? '—'
            : number_format((float) $amount, 2, '.', ',').' '.$currency;
    }

    public static function dateTime(?CarbonInterface $at): string
    {
        return $at === null
            ? '—'
            : $at->copy()->setTimezone(Setting::current()->timezone)->format('d M Y H:i');
    }

    public static function time(?CarbonInterface $at): string
    {
        return $at === null
            ? '—'
            : $at->copy()->setTimezone(Setting::current()->timezone)->format('H:i');
    }

    /** "2 h 05 min" */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours > 0
            ? sprintf('%d h %02d min', $hours, $rest)
            : sprintf('%d min', $rest);
    }
}
