<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row configuration table. Always access through Setting::current().
 */
class Setting extends Model
{
    protected $fillable = [
        'parking_name',
        'address',
        'total_capacity',
        'currency',
        'lost_ticket_fee',
        'ticket_header',
        'ticket_footer',
        'ticket_paper_width_mm',
        'timezone',
        'reason_threshold_percent',
    ];

    protected function casts(): array
    {
        return [
            'total_capacity' => 'decimal:2',
            'lost_ticket_fee' => 'decimal:2',
            'ticket_paper_width_mm' => 'integer',
            'reason_threshold_percent' => 'decimal:2',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'parking_name' => 'Parking',
            'timezone' => config('app.timezone'),
        ]);
    }
}
