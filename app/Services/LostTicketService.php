<?php

namespace App\Services;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class LostTicketService
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly ShiftService $shifts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Closes an active ticket as lost. The customer pays the lost-ticket fee from settings.
     * The stay's calculated price is kept for reference. It is not charged.
     */
    public function markLost(ParkingSession $session, User $operator, ?CarbonInterface $at = null): ParkingSession
    {
        if (! $operator->can(Permissions::CHECKOUT)) {
            throw new AuthorizationException('You are not allowed to close tickets.');
        }

        $at ??= now();

        return DB::transaction(function () use ($session, $operator, $at) {
            $session = ParkingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== ParkingSession::STATUS_ACTIVE) {
                throw new ParkingException('This ticket is already closed ('.$session->status.').');
            }

            $this->shifts->ensureOpen($operator, $at);

            $result = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, $at);
            $fee = (float) Setting::current()->lost_ticket_fee;

            $session->fill([
                'exited_at' => $at,
                'duration_minutes' => $result->durationMinutes,
                'calculated_price' => $result->total(),
                'final_price' => $fee,
                'adjustment_reason' => 'Lost ticket fee',
                // Not an operator adjustment, so adjusted_by stays empty and it is not counted as one.
                'adjusted_by' => null,
                'exit_user_id' => $operator->id,
                'status' => ParkingSession::STATUS_LOST,
            ])->save();

            $this->audit->record(
                event: 'ticket.lost',
                auditable: $session,
                new: ['final_price' => $fee, 'calculated_price' => $result->total()],
                user: $operator,
            );

            return $session;
        });
    }
}
