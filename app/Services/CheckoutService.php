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

/**
 * Closes a ticket and records the price and the cash handled. The price is always computed from the
 * ticket's own tariff snapshot. Any change away from the calculated price is an adjustment and is
 * enforced here, not in the UI, so a crafted request cannot bypass it.
 */
class CheckoutService
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly ShiftService $shifts,
        private readonly AuditLogger $audit,
    ) {}

    public function calculate(ParkingSession $session, ?CarbonInterface $exitAt = null): PriceResult
    {
        return $this->calculator->calculate(
            $session->tariff_snapshot,
            $session->entered_at,
            $exitAt ?? now(),
        );
    }

    /**
     * @param  float|null  $finalPrice      null charges the calculated price
     * @param  float|null  $amountReceived  cash handed over by the customer; null when not counted
     *
     * @throws ParkingException      ticket not open, cash short, reason needed, discount over the limit, negative price
     * @throws AuthorizationException the operator may not change the price
     */
    public function complete(
        ParkingSession $session,
        User $operator,
        ?float $finalPrice = null,
        ?string $reason = null,
        ?CarbonInterface $at = null,
        ?float $amountReceived = null,
    ): ParkingSession {
        $at ??= now();

        return DB::transaction(function () use ($session, $operator, $finalPrice, $reason, $at, $amountReceived) {
            // Lock the ticket so it cannot be checked out twice in parallel.
            $session = ParkingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== ParkingSession::STATUS_ACTIVE) {
                throw new ParkingException('Kjo biletë është mbyllur tashmë ('.\App\Models\ParkingSession::statusLabel($session->status).').');
            }

            $this->shifts->ensureOpen($operator, $at);

            $result = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, $at);
            $calculated = $result->total();
            $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

            // An admin may have already set a custom price on this ticket while it was still
            // active (TicketEditor::edit). That price is stored in the same final_price column,
            // ahead of checkout. If the operator doesn't type a different number here, charge the
            // preset as-is — it was already authorized and audited when the admin set it, so the
            // operator checking this ticket out doesn't need adjust_price themselves just to
            // accept a number they had no part in choosing. If the operator types their own
            // price instead (whether or not a preset existed), that's a fresh adjustment and the
            // normal rules apply to them.
            $preset = $session->final_price !== null ? (float) $session->final_price : null;

            if ($finalPrice !== null) {
                $final = round($finalPrice, 2);
                $adjustedBy = $operator;
                $adjustmentReason = $reason;
            } else {
                $final = $preset ?? $calculated;
                $adjustedBy = $preset !== null ? (User::find($session->adjusted_by) ?? $operator) : $operator;
                $adjustmentReason = $preset !== null ? $session->adjustment_reason : $reason;
            }

            $adjusted = abs($final - $calculated) > 0.004;

            if ($adjusted && $finalPrice !== null) {
                $this->assertMayAdjust($operator, $calculated, $final, $reason);
            }

            [$received, $change] = $this->cashFor($final, $amountReceived);

            $session->fill([
                'exited_at' => $at,
                'duration_minutes' => $result->durationMinutes,
                'calculated_price' => $calculated,
                'final_price' => $final,
                'amount_received' => $received,
                'change_given' => $change,
                'adjustment_reason' => $adjusted ? $adjustmentReason : null,
                'adjusted_by' => $adjusted ? $adjustedBy->id : null,
                'exit_user_id' => $operator->id,
                'status' => ParkingSession::STATUS_PAID,
            ])->save();

            if ($adjusted) {
                $this->audit->record(
                    event: 'price.adjusted',
                    auditable: $session,
                    old: ['final_price' => $calculated],
                    new: ['final_price' => $final],
                    reason: $adjustmentReason,
                    user: $adjustedBy,
                );
            }

            return $session;
        });
    }

    /**
     * Cash handling. The operator must always record the amount received when there is anything
     * to charge — this is required, not optional, so every payment is recorded. The only
     * exception is a free stay (nothing to collect). The amount received must cover the price;
     * the change is what goes back to the customer.
     *
     * @return array{0: ?float, 1: ?float}  [received, change]
     */
    public function cashFor(float $final, ?float $amountReceived): array
    {
        if ($final <= 0) {
            // Nothing to collect — e.g. a stay within the grace period.
            return [null, null];
        }

        if ($amountReceived === null) {
            throw new ParkingException('Shkruani shumën e marrë nga klienti.');
        }

        $received = round($amountReceived, 2);

        if ($received < $final) {
            throw new ParkingException(sprintf(
                'Shuma e marrë mungon me %s.',
                \App\Support\Format::amount($final - $received),
            ));
        }

        return [$received, round($received - $final, 2)];
    }

    /**
     * Whether a price change needs a written reason. A change below the admin threshold (a percentage of
     * the calculated price) can be made without one. A threshold of 0 means every change needs a reason.
     */
    public function reasonRequired(float $calculated, float $final): bool
    {
        $threshold = (float) Setting::current()->reason_threshold_percent;

        if ($threshold <= 0 || $calculated <= 0) {
            return true;
        }

        $changePercent = abs($final - $calculated) / $calculated * 100;

        return $changePercent >= $threshold;
    }

    /**
     * Enforces: permission to adjust, a reason when the change is large enough, no negative price,
     * and the role's discount limit. Raising the price needs the permission but no discount limit.
     */
    private function assertMayAdjust(User $operator, float $calculated, float $final, ?string $reason): void
    {
        if (! $operator->can(Permissions::ADJUST_PRICE)) {
            throw new AuthorizationException('Nuk keni leje të ndryshoni çmimin.');
        }

        if ($reason === null && $this->reasonRequired($calculated, $final)) {
            throw new ParkingException('Shkruani arsyen e ndryshimit të çmimit.');
        }

        if ($final < 0) {
            throw new ParkingException('Çmimi përfundimtar nuk mund të jetë negativ.');
        }

        if ($final < $calculated && $calculated > 0) {
            $discount = round(($calculated - $final) / $calculated * 100, 2);
            $limit = $operator->maxDiscountPercent();

            if ($limit !== null && $discount > $limit) {
                throw new ParkingException(sprintf(
                    'Zbritja prej %s%% është mbi limitin tuaj prej %s%%.',
                    \App\Support\Format::amount($discount, 2, true),
                    \App\Support\Format::amount($limit, 2, true),
                ));
            }
        }
    }
}
