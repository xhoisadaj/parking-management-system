<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Display formatting for operator screens, printed tickets, and exports. Everything is in Albanian.
 */
final class Format
{
    public static function money(float|string|null $amount, ?string $currency = null): string
    {
        $currency ??= Setting::current()->currency;

        return $amount === null
            ? '—'
            : self::amount($amount).' '.$currency;
    }

    /**
     * A plain number in Albanian style (comma for decimals, dot for thousands), without currency.
     * $trim drops trailing zeros: 60 -> "60", 51.5 -> "51,5".
     */
    public static function amount(float|string $value, int $decimals = 2, bool $trim = false): string
    {
        $text = number_format((float) $value, $decimals, ',', '.');

        return $trim && $decimals > 0 ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** "05 Tet 2026 14:30" */
    public static function dateTime(?CarbonInterface $at): string
    {
        return $at === null
            ? '—'
            : $at->copy()->setTimezone(Setting::current()->timezone)->locale('sq')->translatedFormat('d M Y H:i');
    }

    public static function time(?CarbonInterface $at): string
    {
        return $at === null
            ? '—'
            : $at->copy()->setTimezone(Setting::current()->timezone)->format('H:i');
    }

    /** "2 orë 05 min" */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours > 0
            ? sprintf('%d orë %02d min', $hours, $rest)
            : sprintf('%d min', $rest);
    }
}
