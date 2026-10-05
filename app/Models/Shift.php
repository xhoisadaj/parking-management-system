<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'opened_at',
        'closed_at',
        'tickets_issued',
        'checkouts',
        'cash_collected',
        'reconciled_by',
        'reconciled_at',
        'reconciliation_note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'tickets_issued' => 'integer',
            'checkouts' => 'integer',
            'cash_collected' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
