<?php

namespace App\Services;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\VehicleType;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Capacity rules: one shared pool (Setting::total_capacity) measured in spots,
 * where each vehicle type consumes spots_used of it, and an optional per-type
 * cap on the number of vehicles of that type (VehicleType::dedicated_capacity).
 */
class CapacityService
{
    public function totalCapacity(): float
    {
        return (float) Setting::current()->total_capacity;
    }

    /**
     * Spots currently taken across all active sessions.
     */
    public function occupiedSpots(): float
    {
        return (float) ParkingSession::query()
            ->active()
            ->join('vehicle_types', 'vehicle_types.id', '=', 'parking_sessions.vehicle_type_id')
            ->sum('vehicle_types.spots_used');
    }

    public function activeVehicleCount(VehicleType $vehicleType): int
    {
        return ParkingSession::query()
            ->active()
            ->where('vehicle_type_id', $vehicleType->id)
            ->count();
    }

    /**
     * How many more vehicles of this type can enter right now (0 when full).
     */
    public function freeVehiclesFor(VehicleType $vehicleType): int
    {
        $spotsUsed = (float) $vehicleType->spots_used;

        if ($spotsUsed <= 0) {
            return PHP_INT_MAX;
        }

        $freeSpots = $this->totalCapacity() - $this->occupiedSpots();
        // Small epsilon so float noise (e.g. 9.999999) does not hide a spot.
        $byPool = (int) floor(($freeSpots + 0.000001) / $spotsUsed);

        if ($vehicleType->dedicated_capacity === null) {
            return max(0, $byPool);
        }

        $byDedicated = $vehicleType->dedicated_capacity - $this->activeVehicleCount($vehicleType);

        return max(0, min($byPool, $byDedicated));
    }

    public function canAdmit(VehicleType $vehicleType): bool
    {
        return $this->freeVehiclesFor($vehicleType) > 0;
    }

    /**
     * Runs $callback inside a transaction while holding a row lock on the settings row.
     * All capacity-changing operations (ticket issuing) go through this so concurrent
     * entries are serialized and the capacity check + insert cannot race.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withPoolLock(Closure $callback): mixed
    {
        Setting::current(); // make sure the row exists before locking it

        return DB::transaction(function () use ($callback) {
            Setting::query()->whereKey(1)->lockForUpdate()->first();

            return $callback();
        });
    }
}
