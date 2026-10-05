<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Tariff extends Model
{
    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    protected $fillable = [
        'vehicle_type_id',
        'name',
        'billing_unit_minutes',
        'price_per_unit',
        'first_unit_price',
        'grace_minutes',
        'daily_max',
        'rounding',
        'active_from',
        'active_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'billing_unit_minutes' => 'integer',
            'price_per_unit' => 'decimal:2',
            'first_unit_price' => 'decimal:2',
            'grace_minutes' => 'integer',
            'daily_max' => 'decimal:2',
            'active_from' => 'date',
            'active_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function timeBands(): HasMany
    {
        return $this->hasMany(TariffTimeBand::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Whether this tariff may be used for a stay starting on the given local date.
     */
    public function isValidOn(CarbonInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $day = $date->toDateString();

        return ($this->active_from === null || $this->active_from->toDateString() <= $day)
            && ($this->active_to === null || $this->active_to->toDateString() >= $day);
    }

    /**
     * Frozen copy stored on the parking session. Prices are always computed from this array.
     */
    public function toSnapshot(): array
    {
        $this->loadMissing('timeBands');

        return [
            'tariff_id' => $this->id,
            'vehicle_type_id' => $this->vehicle_type_id,
            'name' => $this->name,
            'timezone' => Setting::current()->timezone,
            'billing_unit_minutes' => $this->billing_unit_minutes,
            'price_per_unit' => (float) $this->price_per_unit,
            'first_unit_price' => $this->first_unit_price !== null ? (float) $this->first_unit_price : null,
            'grace_minutes' => $this->grace_minutes,
            'daily_max' => $this->daily_max !== null ? (float) $this->daily_max : null,
            'rounding' => $this->rounding,
            'time_bands' => $this->timeBands->map(fn (TariffTimeBand $band) => [
                'label' => $band->label,
                'starts_at' => substr((string) $band->starts_at, 0, 5),
                'ends_at' => substr((string) $band->ends_at, 0, 5),
                'price_per_unit' => (float) $band->price_per_unit,
            ])->values()->all(),
        ];
    }
}
