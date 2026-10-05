<?php

namespace App\Models;

use Database\Factories\ParkingSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParkingSession extends Model
{
    /** @use HasFactory<ParkingSessionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUS_LOST = 'lost';

    protected $fillable = [
        'ticket_code',
        'vehicle_type_id',
        'plate',
        'entered_at',
        'exited_at',
        'duration_minutes',
        'calculated_price',
        'final_price',
        'adjustment_reason',
        'adjusted_by',
        'entry_user_id',
        'exit_user_id',
        'status',
        'tariff_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'duration_minutes' => 'integer',
            'calculated_price' => 'decimal:2',
            'final_price' => 'decimal:2',
            'tariff_snapshot' => 'array',
        ];
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function entryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entry_user_id');
    }

    public function exitUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exit_user_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
