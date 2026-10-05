<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes append-only audit entries. Used for price adjustments and configuration changes.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $event,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?User $user = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'user_id' => ($user ?? Auth::user())?->getKey(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);
    }
}
