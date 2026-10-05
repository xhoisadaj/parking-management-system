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
                throw new ParkingException('This ticket is already closed ('.$session->status.').');
            }

            $this->shifts->ensureOpen($operator, $at);

            $result = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, $at);
            $calculated = $result->total();
            $final = $finalPrice === null ? $calculated : round($finalPrice, 2);
            $adjusted = abs($final - $calculated) > 0.004;
            $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

            if ($adjusted) {
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
                'adjustment_reason' => $adjusted ? $reason : null,
                'adjusted_by' => $adjusted ? $operator->id : null,
                'exit_user_id' => $operator->id,
                'status' => ParkingSession::STATUS_PAID,
            ])->save();

            if ($adjusted) {
                $this->audit->record(
                    event: 'price.adjusted',
                    auditable: $session,
                    old: ['final_price' => $calculated],
                    new: ['final_price' => $final],
                    reason: $reason,
                    user: $operator,
                );
            }

            return $session;
        });
    }

    /**
     * Cash handling: the amount received must cover the price. The change is what goes back to the customer.
     *
     * @return array{0: ?float, 1: ?float}  [received, change]
     */
    public function cashFor(float $final, ?float $amountReceived): array
    {
        if ($amountReceived === null) {
            return [null, null];
        }

        $received = round($amountReceived, 2);

        if ($received < $final) {
            throw new ParkingException(sprintf(
                'The amount received is short by %s.',
                number_format($final - $received, 2),
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
            throw new AuthorizationException('You are not allowed to change the price.');
        }

        if ($reason === null && $this->reasonRequired($calculated, $final)) {
            throw new ParkingException('Enter a reason for changing the price.');
        }

        if ($final < 0) {
            throw new ParkingException('The final price cannot be negative.');
        }

        if ($final < $calculated && $calculated > 0) {
            $discount = round(($calculated - $final) / $calculated * 100, 2);
            $limit = $operator->maxDiscountPercent();

            if ($limit !== null && $discount > $limit) {
                throw new ParkingException(sprintf(
                    'A %s%% discount is above your limit of %s%%.',
                    rtrim(rtrim(number_format($discount, 2), '0'), '.'),
                    rtrim(rtrim(number_format($limit, 2), '0'), '.'),
                ));
            }
        }
    }
}
