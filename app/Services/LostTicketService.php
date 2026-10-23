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
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * Closes an active ticket as lost. The customer pays the stay's normal price plus the
     * lost-ticket fee from settings on top, so losing a ticket never costs less than keeping it.
     * calculated_price keeps the stay price on its own; final_price is stay + fee.
     */
    public function markLost(ParkingSession $session, User $operator, ?CarbonInterface $at = null, ?float $amountReceived = null): ParkingSession
    {
        if (! $operator->can(Permissions::CHECKOUT)) {
            throw new AuthorizationException('Nuk keni leje të mbyllni biletat.');
        }

        $at ??= now();

        return DB::transaction(function () use ($session, $operator, $at, $amountReceived) {
            $session = ParkingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== ParkingSession::STATUS_ACTIVE) {
                throw new ParkingException('Kjo biletë është mbyllur tashmë ('.\App\Models\ParkingSession::statusLabel($session->status).').');
            }

            $this->shifts->ensureOpen($operator, $at);

            $result = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, $at);
            $fee = (float) Setting::current()->lost_ticket_fee;
            $total = round($result->total() + $fee, 2);
            [$received, $change] = $this->checkout->cashFor($total, $amountReceived);

            $session->fill([
                'exited_at' => $at,
                'duration_minutes' => $result->durationMinutes,
                'calculated_price' => $result->total(),
                'final_price' => $total,
                'amount_received' => $received,
                'change_given' => $change,
                'adjustment_reason' => 'Tarifa e biletës së humbur',
                // Not an operator adjustment, so adjusted_by stays empty and it is not counted as one.
                'adjusted_by' => null,
                'exit_user_id' => $operator->id,
                'status' => ParkingSession::STATUS_LOST,
            ])->save();

            $this->audit->record(
                event: 'ticket.lost',
                auditable: $session,
                new: ['final_price' => $total, 'calculated_price' => $result->total(), 'lost_ticket_fee' => $fee],
                user: $operator,
            );

            return $session;
        });
    }
}
