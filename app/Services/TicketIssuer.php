<?php

namespace App\Services;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\CarbonInterface;

class TicketIssuer
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly TariffResolver $tariffs,
        private readonly TicketCodeGenerator $codes,
        private readonly ShiftService $shifts,
    ) {}

    /**
     * Issues an entry ticket. The capacity check and the insert run under the pool lock,
     * so concurrent entries cannot push the lot over capacity.
     *
     * @throws ParkingException when the type is inactive, full, or has no valid tariff
     */
    public function issue(VehicleType $vehicleType, ?string $plate, User $operator, ?CarbonInterface $at = null): ParkingSession
    {
        $at ??= now();
        $plate = $this->normalizePlate($plate);

        return $this->capacity->withPoolLock(function () use ($vehicleType, $plate, $operator, $at) {
            $vehicleType->refresh();

            if (! $vehicleType->is_active) {
                throw new ParkingException("{$vehicleType->name} nuk është e disponueshme tani.");
            }

            if (! $this->capacity->canAdmit($vehicleType)) {
                throw new ParkingException("Nuk ka vende të lira për {$vehicleType->name}.");
            }

            $tariff = $this->tariffs->forVehicleType($vehicleType, $at);

            if ($tariff === null) {
                throw new ParkingException("Nuk ka tarifë aktive për {$vehicleType->name}. Kërkoni te menaxheri të kontrollojë tarifat.");
            }

            $this->shifts->ensureOpen($operator, $at);

            return ParkingSession::create([
                'ticket_code' => $this->codes->generate(),
                'vehicle_type_id' => $vehicleType->id,
                'plate' => $plate,
                'entered_at' => $at,
                'status' => ParkingSession::STATUS_ACTIVE,
                'entry_user_id' => $operator->id,
                // Frozen here. Later tariff edits never change this ticket's price.
                'tariff_snapshot' => $tariff->toSnapshot(),
            ]);
        });
    }

    private function normalizePlate(?string $plate): ?string
    {
        $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $plate));

        return $plate === '' ? null : substr($plate, 0, 20);
    }
}
