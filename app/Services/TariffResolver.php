<?php

namespace App\Services;

use App\Models\Tariff;
use App\Models\VehicleType;
use Illuminate\Support\Carbon;

class TariffResolver
{
    /**
     * The tariff that applies to a stay of this vehicle type starting at the given instant
     * (evaluated in the app timezone). Returns null when no tariff is valid.
     */
    public function forVehicleType(VehicleType $vehicleType, Carbon $enteredAt): ?Tariff
    {
        $date = $enteredAt->copy()->setTimezone(config('app.timezone'));

        return $vehicleType->tariffs()
            ->with('timeBands')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->first(fn (Tariff $tariff) => $tariff->isValidOn($date));
    }
}
