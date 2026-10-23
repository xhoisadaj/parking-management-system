<?php

namespace App\Services;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\User;
use App\Models\VehicleType;
use App\Support\Format;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Lets an admin correct a ticket from the admin panel:
 *  - edit(): plate, vehicle type, entry time, and/or a custom price, while the ticket is still
 *    active (not yet checked out). A vehicle-type or entry-time change re-takes the tariff
 *    snapshot the same way TicketIssuer does at entry, so checkout still prices the ticket
 *    consistently with what it now says. A price override is stored on the same final_price
 *    column CheckoutService eventually fills in — see CheckoutService::complete(), which charges
 *    it as-is unless the operator checking the car out types a different price themselves.
 *  - correctPrice(): the final price of a ticket that has already been checked out (paid or
 *    marked lost), for when the amount charged at the register turns out to have been wrong.
 *
 * Either way, changing the price always needs a price permission (adjust_price from Menaxhimi, or
 * the narrower adjust_own_ticket_price from Biletat e mia — see applyPriceOverride()), a reason,
 * and respects the acting user's role discount limit when it's a reduction — same accountability
 * as a live checkout adjustment, enforced here too so this isn't a way around it.
 *
 * There is nothing to print specially: the entry ticket and receipt pages always read the session
 * fresh from the database, so the next time one is printed — including the "Printo" action on the
 * tickets list — it reflects the correction automatically.
 *
 * Voided tickets are not editable here — see VoidService for undoing one that was issued by
 * mistake.
 */
class TicketEditor
{
    public function __construct(
        private readonly TariffResolver $tariffs,
        private readonly PriceCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly CapacityService $capacity,
    ) {}

    /**
     * @param  ?float  $priceOverride        a custom price to charge at checkout regardless of the
     *                                       calculated total; null clears a previously set override
     *                                       (same "blank means default" rule as the checkout
     *                                       screen), and passing the current one back unchanged
     *                                       leaves it alone
     * @param  ?string  $priceOverrideReason required whenever $priceOverride is given
     * @param  string  $pricePermission      which permission $admin must hold to set/clear the
     *                                       override here — Menaxhimi's Biletat page (the default)
     *                                       requires adjust_price; Biletat e mia passes
     *                                       adjust_own_ticket_price instead, so holding one does
     *                                       not also unlock the other caller
     *
     * @throws ParkingException       the ticket is not active, the entry time is in the future, there is
     *                                no room for the new vehicle type, no tariff
     *                                applies to the (possibly new) vehicle type, the override is negative,
     *                                no reason was given for it, or it — or a vehicle/entry-time change
     *                                that lowers the price — discounts past the admin's role limit
     * @throws AuthorizationException the admin may not adjust prices (when setting or clearing an
     *                                override, or when a vehicle/entry-time change lowers the price)
     */
    public function edit(
        ParkingSession $session,
        User $admin,
        VehicleType $vehicleType,
        ?string $plate,
        CarbonInterface $enteredAt,
        ?float $priceOverride = null,
        ?string $priceOverrideReason = null,
        string $pricePermission = Permissions::ADJUST_PRICE,
    ): ParkingSession {
        // Under the pool lock, like TicketIssuer, so a vehicle-type change and a new entry can't
        // both take the last free spot.
        return $this->capacity->withPoolLock(function () use ($session, $admin, $vehicleType, $plate, $enteredAt, $priceOverride, $priceOverrideReason, $pricePermission) {
            // Lock the ticket so it cannot be checked out (or edited again) while this runs.
            $session = ParkingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== ParkingSession::STATUS_ACTIVE) {
                throw new ParkingException('Vetëm biletat aktive mund të ndryshohen ('.ParkingSession::statusLabel($session->status).').');
            }

            if ($enteredAt->isFuture()) {
                throw new ParkingException('Ora e hyrjes nuk mund të jetë në të ardhmen.');
            }

            $plate = $this->normalizePlate($plate);
            $vehicleChanged = $vehicleType->id !== $session->vehicle_type_id;
            $timeChanged = ! $enteredAt->equalTo($session->entered_at);

            $old = [
                'vehicle_type_id' => $session->vehicle_type_id,
                'plate' => $session->plate,
                'entered_at' => $session->entered_at->toIso8601String(),
            ];

            $priceBefore = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, now())->total();

            if ($vehicleChanged && ! $this->capacity->canSwitchTo($session, $vehicleType)) {
                throw new ParkingException("Nuk ka vende të lira për {$vehicleType->name}.");
            }

            if ($vehicleChanged || $timeChanged) {
                // Re-take the snapshot so checkout prices this ticket by the vehicle type and
                // entry time it now shows, not the ones it was issued under.
                $tariff = $this->tariffs->forVehicleType($vehicleType, $enteredAt);

                if ($tariff === null) {
                    throw new ParkingException("Nuk ka tarifë aktive për {$vehicleType->name} në atë datë.");
                }

                $session->tariff_snapshot = $tariff->toSnapshot();
                $session->vehicle_type_id = $vehicleType->id;
            }

            $session->plate = $plate;
            $session->entered_at = $enteredAt;

            // A later entry time or a cheaper vehicle type lowers what checkout will charge just
            // as surely as a typed price would, so it gets the same accountability: the price
            // permission, and the role's discount limit measured against the price before the
            // edit. Without this, moving the entry time to a minute ago zeroes the ticket.
            $priceAfter = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, now())->total();

            if ($priceAfter < $priceBefore - 0.004) {
                if (! $admin->can($pricePermission)) {
                    throw new AuthorizationException('Ky ndryshim ul çmimin e biletës. Nuk keni leje të ndryshoni çmimin.');
                }

                $this->assertWithinDiscountLimit($admin, $priceBefore, $priceAfter);
            }

            $session->save();

            $this->audit->record(
                event: 'ticket.edited',
                auditable: $session,
                old: $old,
                new: [
                    'vehicle_type_id' => $session->vehicle_type_id,
                    'plate' => $session->plate,
                    'entered_at' => $session->entered_at->toIso8601String(),
                ],
                user: $admin,
            );

            $this->applyPriceOverride($session, $admin, $priceOverride, $priceOverrideReason, $pricePermission);

            return $session;
        });
    }

    /**
     * Sets (or clears) the custom price an admin can put on an active ticket, ahead of checkout —
     * CheckoutService::complete() charges it as-is unless the operator types their own price.
     * Same accountability as any other price change: permission, a reason, and the role's
     * discount limit when it's a reduction.
     */
    private function applyPriceOverride(ParkingSession $session, User $admin, ?float $priceOverride, ?string $reason, string $pricePermission = Permissions::ADJUST_PRICE): void
    {
        $old = ['final_price' => $session->final_price, 'adjustment_reason' => $session->adjustment_reason];

        // The edit forms pre-fill the current override, so a form saved for a plate fix sends it
        // straight back. An unchanged override is left alone: no permission check, no new audit
        // entry, and no re-check of the discount limit against a calculated price that has grown
        // since it was set.
        if ($priceOverride !== null
            && $session->final_price !== null
            && abs(round($priceOverride, 2) - (float) $session->final_price) < 0.005
            && trim((string) $reason) === (string) $session->adjustment_reason) {
            return;
        }

        // Which permission is required depends on the caller, not just whichever one $admin
        // happens to hold — see $pricePermission on edit(). Otherwise an operator holding only
        // adjust_price (for checkout discounts) could also set a price here, which is exactly the
        // cross-over Permissions::individuallyGrantablePermissions() means to prevent.
        $mayChangePrice = $admin->can($pricePermission);

        if ($priceOverride === null) {
            if ($session->final_price === null) {
                return; // nothing to clear
            }

            // Clearing is a price change too — it can undo a price someone else set on purpose.
            if (! $mayChangePrice) {
                throw new AuthorizationException('Nuk keni leje të ndryshoni çmimin.');
            }

            $session->final_price = null;
            $session->adjustment_reason = null;
            $session->adjusted_by = null;
            $session->save();

            $this->audit->record(
                event: 'ticket.price_override_cleared',
                auditable: $session,
                old: $old,
                new: ['final_price' => null],
                user: $admin,
            );

            return;
        }

        if (! $mayChangePrice) {
            throw new AuthorizationException('Nuk keni leje të ndryshoni çmimin.');
        }

        $priceOverride = round($priceOverride, 2);

        if ($priceOverride < 0) {
            throw new ParkingException('Çmimi nuk mund të jetë negativ.');
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new ParkingException('Shkruani arsyen e çmimit të caktuar.');
        }

        $calculated = $this->calculator->calculate($session->tariff_snapshot, $session->entered_at, now())->total();
        $this->assertWithinDiscountLimit($admin, $calculated, $priceOverride);

        $session->final_price = $priceOverride;
        $session->adjustment_reason = $reason;
        $session->adjusted_by = $admin->id;
        $session->save();

        $this->audit->record(
            event: 'ticket.price_override_set',
            auditable: $session,
            old: $old,
            new: ['final_price' => $priceOverride, 'adjustment_reason' => $reason],
            reason: $reason,
            user: $admin,
        );
    }

    /**
     * Corrects the final price of a closed ticket (paid or lost). The role's discount limit
     * still applies when the correction lowers the price, and a reason is always required — same
     * accountability as an adjustment made live at checkout, just after the fact.
     *
     * @throws ParkingException       the ticket isn't closed, the price is negative, no reason was given,
     *                                or the discount is over the admin's role limit
     * @throws AuthorizationException the admin may not adjust prices
     */
    public function correctPrice(ParkingSession $session, User $admin, float $finalPrice, string $reason): ParkingSession
    {
        return DB::transaction(function () use ($session, $admin, $finalPrice, $reason) {
            $session = ParkingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST], true)) {
                throw new ParkingException('Vetëm biletat e mbyllura (të paguara ose të humbura) mund të korrigjohen këtu.');
            }

            if (! $admin->can(Permissions::ADJUST_PRICE)) {
                throw new AuthorizationException('Nuk keni leje të ndryshoni çmimin.');
            }

            $finalPrice = round($finalPrice, 2);

            if ($finalPrice < 0) {
                throw new ParkingException('Çmimi përfundimtar nuk mund të jetë negativ.');
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new ParkingException('Shkruani arsyen e korrigjimit.');
            }

            $this->assertWithinDiscountLimit($admin, (float) $session->calculated_price, $finalPrice);

            $old = [
                'final_price' => (float) $session->final_price,
                'adjustment_reason' => $session->adjustment_reason,
            ];

            $session->final_price = $finalPrice;
            $session->adjustment_reason = $reason;
            $session->adjusted_by = $admin->id;
            $session->save();

            $this->audit->record(
                event: 'price.corrected',
                auditable: $session,
                old: $old,
                new: ['final_price' => $finalPrice, 'adjustment_reason' => $reason],
                reason: $reason,
                user: $admin,
            );

            return $session;
        });
    }

    /**
     * A reduction from $calculated to $final must stay within $user's role discount limit.
     * Raising the price is never limited.
     *
     * @throws ParkingException the discount is over the limit
     */
    private function assertWithinDiscountLimit(User $user, float $calculated, float $final): void
    {
        if ($final >= $calculated || $calculated <= 0) {
            return;
        }

        $discount = round(($calculated - $final) / $calculated * 100, 2);
        $limit = $user->maxDiscountPercent();

        if ($limit !== null && $discount > $limit) {
            throw new ParkingException(sprintf(
                'Zbritja prej %s%% është mbi limitin tuaj prej %s%%.',
                Format::amount($discount, 2, true),
                Format::amount($limit, 2, true),
            ));
        }
    }

    private function normalizePlate(?string $plate): ?string
    {
        $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $plate));

        return $plate === '' ? null : substr($plate, 0, 20);
    }
}
