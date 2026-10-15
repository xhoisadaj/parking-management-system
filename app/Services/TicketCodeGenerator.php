<?php

namespace App\Services;

use App\Models\ParkingSession;

/**
 * Generates short, unambiguous, Code128-friendly ticket codes (no 0/O or 1/I/L).
 *
 * Default length is 6, not 8: each extra character is ~11 more Code128 modules squeezed into
 * the same printed barcode width, and on a 58mm ticket that difference is the gap between a
 * cheap handheld scanner reading it reliably and losing sync partway through. 32^6 (~1.07
 * billion codes, checked for uniqueness on generate) is still far beyond any collision risk a
 * single lot's ticket volume could reach.
 */
class TicketCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function generate(int $length = 6): string
    {
        do {
            $code = $this->random($length);
        } while (ParkingSession::query()->where('ticket_code', $code)->exists());

        return $code;
    }

    private function random(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
