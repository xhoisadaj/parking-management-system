<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One flat-price step of a tariff's duration-tier pricing (see PriceCalculator). A tariff that
 * has any of these switches that day-period's pricing from the usual billing-unit calculation to
 * a flat lookup: once elapsed minutes within the day-period reach this tier, the whole period is
 * charged `price`, not price_per_unit x units. up_to_minutes null means "and beyond" — the last,
 * open-ended tier.
 */
class TariffDurationTier extends Model
{
    protected $fillable = [
        'tariff_id',
        'label',
        'up_to_minutes',
        'price',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'up_to_minutes' => 'integer',
            'price' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }
}
