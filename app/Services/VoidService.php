<?php

namespace App\Services;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Voids an active ticket issued by mistake, freeing its spot. Requires a reason.
 */
class VoidService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function void(ParkingSession $session, User $operator, string $reason): ParkingSession
    {
        if (! $operator->can(Permissions::VOID_TICKET)) {
            throw new AuthorizationException('Nuk keni leje të anuloni biletat.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new ParkingException('Shkruani arsyen e anulimit.');
        }

        return DB::transaction(function () use ($session, $operator, $reason) {
            $session = ParkingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== ParkingSession::STATUS_ACTIVE) {
                throw new ParkingException('Vetëm biletat aktive mund të anulohen.');
            }

            // Not a price adjustment, so adjusted_by stays empty. The reason is kept in the audit log.
            $session->update(['status' => ParkingSession::STATUS_VOID]);

            $this->audit->record(
                event: 'ticket.voided',
                auditable: $session,
                old: ['status' => ParkingSession::STATUS_ACTIVE],
                new: ['status' => ParkingSession::STATUS_VOID],
                reason: $reason,
                user: $operator,
            );

            return $session;
        });
    }
}
