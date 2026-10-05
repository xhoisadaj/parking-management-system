<?php

namespace App\Services;

use App\Models\ParkingSession;

/**
 * Generates short, unambiguous, Code128-friendly ticket codes (no 0/O or 1/I/L).
 */
class TicketCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function generate(int $length = 8): string
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
