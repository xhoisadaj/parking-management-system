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
        'opening_cash',
        'opened_at',
        'closed_at',
        'tickets_issued',
        'checkouts',
        'cash_collected',
        'closing_cash_declared',
        'cash_counted',
        'reconciled_by',
        'reconciled_at',
        'reconciliation_note',
        'work_shift_id',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'tickets_issued' => 'integer',
            'checkouts' => 'integer',
            'cash_collected' => 'decimal:2',
            'closing_cash_declared' => 'decimal:2',
            'cash_counted' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /** What the drawer should hold at close: the float the operator started with, plus what came in. */
    public function expectedCash(): float
    {
        return round((float) ($this->opening_cash ?? 0) + (float) $this->cash_collected, 2);
    }
}
