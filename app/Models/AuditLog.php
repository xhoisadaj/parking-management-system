<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only. There is no updated_at column and nothing should update or delete rows.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'reason',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Albanian label for an audit event code. */
    public static function eventLabel(string $event): string
    {
        return match ($event) {
            'price.adjusted' => 'Ndryshim i çmimit',
            'ticket.lost' => 'Biletë e humbur',
            'ticket.voided' => 'Biletë e anuluar',
            'settings.updated' => 'Cilësimet u ndryshuan',
            default => $event,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
