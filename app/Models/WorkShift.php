<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled work shift, e.g. "Morning 06:00-14:00". Operators are assigned one.
 */
class WorkShift extends Model
{
    protected $fillable = ['name', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** "06:00 – 14:00" */
    public function hoursLabel(): string
    {
        return substr((string) $this->starts_at, 0, 5).' – '.substr((string) $this->ends_at, 0, 5);
    }

    /** Whether the given local time falls inside the scheduled hours. */
    public function covers(\DateTimeInterface $localTime): bool
    {
        $now = $localTime->format('H:i');
        $start = substr((string) $this->starts_at, 0, 5);
        $end = substr((string) $this->ends_at, 0, 5);

        return $start < $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }
}
