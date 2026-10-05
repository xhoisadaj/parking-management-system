<?php

namespace App\Models;

use Database\Factories\VehicleTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleType extends Model
{
    /** @use HasFactory<VehicleTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'spots_used',
        'dedicated_capacity',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'spots_used' => 'decimal:2',
            'dedicated_capacity' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    public function parkingSessions(): HasMany
    {
        return $this->hasMany(ParkingSession::class);
    }
}
