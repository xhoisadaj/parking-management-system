<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningHour extends Model
{
    protected $fillable = [
        'weekday',
        'is_closed',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    /**
     * Whether the lot is open at the given local instant.
     * A closes_at earlier than opens_at means the lot runs past midnight.
     */
    public function isOpenAt(\DateTimeInterface $localTime): bool
    {
        if ($this->is_closed || $this->opens_at === null || $this->closes_at === null) {
            return false;
        }

        $now = substr($localTime->format('H:i:s'), 0, 8);
        $opens = substr((string) $this->opens_at, 0, 8);
        $closes = substr((string) $this->closes_at, 0, 8);

        return $opens < $closes
            ? $now >= $opens && $now < $closes
            : $now >= $opens || $now < $closes;
    }
}
