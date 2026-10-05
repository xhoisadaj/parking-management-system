<?php

namespace App\Models;

use Database\Factories\TariffTimeBandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TariffTimeBand extends Model
{
    /** @use HasFactory<TariffTimeBandFactory> */
    use HasFactory;

    protected $fillable = [
        'tariff_id',
        'label',
        'starts_at',
        'ends_at',
        'price_per_unit',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price_per_unit' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }
}
