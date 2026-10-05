<?php

namespace Database\Seeders;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\Tariff;
use App\Models\User;
use App\Models\WorkShift;
use App\Models\VehicleType;
use App\Services\PriceCalculator;
use App\Services\TicketCodeGenerator;
use App\Services\TariffResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * About four weeks of realistic demo activity, so the statistics have something to show.
 * Deterministic (fixed random seed): running it again on the same day gives the same figures.
 * Refuses to run in production.
 */
class DemoDataSeeder extends Seeder
{
    private const DAYS = 28;

    /** Relative arrival weight for each hour of the day (0-23). */
    private const HOUR_WEIGHTS = [
        1, 1, 1, 1, 1, 1, 4, 10, 14, 12, 10, 9, 9, 9, 8, 8, 9, 12, 13, 11, 8, 5, 3, 2,
    ];

    private const REASONS = [
        'Regular customer',
        'Damaged ticket',
        'Monthly pass holder',
        'Promotion',
        'Staff discount',
    ];

    /** Discount percentages a manager gives on an adjusted ticket. Always within the Manager limit (20%). */
    private const DISCOUNTS = [10, 15, 20];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Demo data is not seeded in production.');

            return;
        }

        mt_srand(20260601);

        $tz = Setting::current()->timezone;
        $now = CarbonImmutable::now($tz);

        $operators = $this->demoUsers();
        $vehicleTypes = VehicleType::query()->where('is_active', true)->get();
        $snapshots = $this->snapshotsByType($vehicleTypes, $now);
        $codes = app(TicketCodeGenerator::class);
        $calculator = app(PriceCalculator::class);
        $fee = (float) Setting::current()->lost_ticket_fee;

        ParkingSession::query()->delete();

        $firstDay = $now->subDays(self::DAYS - 1)->startOfDay();

        for ($day = 0; $day < self::DAYS; $day++) {
            $date = $firstDay->addDays($day);
            $weekend = in_array($date->dayOfWeekIso, [6, 7], true);
            $arrivals = (int) round((50 + mt_rand(-8, 8)) * ($weekend ? 1.25 : 1.0));

            for ($i = 0; $i < $arrivals; $i++) {
                $hour = $this->weightedHour();
                $entered = $date->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

                if ($entered->greaterThan($now)) {
                    continue;
                }

                $vehicle = $this->pickVehicle($vehicleTypes);
                $snapshot = $snapshots[$vehicle->id];
                $duration = $this->duration();
                $exited = $entered->addMinutes($duration);
                $entryUser = $operators[array_rand($operators)];

                $session = [
                    'ticket_code' => $codes->generate(),
                    'vehicle_type_id' => $vehicle->id,
                    'plate' => $this->plate(),
                    'entered_at' => $entered,
                    'entry_user_id' => $entryUser->id,
                    'tariff_snapshot' => $snapshot,
                ];

                if ($exited->greaterThan($now)) {
                    ParkingSession::create($session + ['status' => ParkingSession::STATUS_ACTIVE]);

                    continue;
                }

                $roll = mt_rand(1, 100);

                if ($roll === 1) {
                    ParkingSession::create($session + ['status' => ParkingSession::STATUS_VOID]);

                    continue;
                }

                $exitUser = $operators[array_rand($operators)];
                $result = $calculator->calculate($snapshot, $entered, $exited);
                $calculated = $result->total();

                if ($roll <= 3) {
                    ParkingSession::create($session + [
                        'exited_at' => $exited,
                        'duration_minutes' => $result->durationMinutes,
                        'calculated_price' => $calculated,
                        'final_price' => $fee,
                        'adjustment_reason' => 'Lost ticket fee',
                        'exit_user_id' => $exitUser->id,
                        'status' => ParkingSession::STATUS_LOST,
                    ]);

                    continue;
                }

                // About 8% of paid tickets get a manager discount, with a reason.
                $adjusted = $calculated > 0 && mt_rand(1, 100) <= 8;
                $final = $calculated;
                $reason = null;
                $adjustedBy = null;

                if ($adjusted) {
                    $discount = self::DISCOUNTS[array_rand(self::DISCOUNTS)];
                    $final = round($calculated * (1 - $discount / 100), 2);
                    $reason = self::REASONS[array_rand(self::REASONS)];
                    $adjustedBy = $this->manager()->id;
                    $exitUser = $this->manager();
                }

                [$received, $change] = $this->cashFor($final);

                ParkingSession::create($session + [
                    'exited_at' => $exited,
                    'duration_minutes' => $result->durationMinutes,
                    'calculated_price' => $calculated,
                    'final_price' => $final,
                    'amount_received' => $received,
                    'change_given' => $change,
                    'adjustment_reason' => $reason,
                    'adjusted_by' => $adjustedBy,
                    'exit_user_id' => $exitUser->id,
                    'status' => ParkingSession::STATUS_PAID,
                ]);
            }
        }

        $this->command?->info('Demo sessions: '.ParkingSession::count());
    }

    /** @return list<User> */
    private function demoUsers(): array
    {
        $ana = $this->user('Ana Operator', 'ana@parking.test', 'Operator', 'Morning');
        $ben = $this->user('Ben Operator', 'ben@parking.test', 'Operator', 'Afternoon');

        return [$ana, $ben, $this->manager()];
    }

    private function manager(): User
    {
        return $this->user('Mira Manager', 'mira@parking.test', 'Manager');
    }

    private function user(string $name, string $email, string $role, ?string $shiftName = null): User
    {
        $user = User::firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => 'password',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $user->syncRoles([$role]);
        $user->update(['work_shift_id' => $shiftName === null ? null : WorkShift::where('name', $shiftName)->value('id')]);

        return $user;
    }

    /**
     * Cash the customer hands over: the next whole 100, so the change is under 100. About 70% of tickets.
     *
     * @return array{0: ?float, 1: ?float}  [received, change]
     */
    private function cashFor(float $final): array
    {
        if (mt_rand(1, 100) > 70) {
            return [null, null];
        }

        $received = ceil($final / 100) * 100;

        return [$received, round($received - $final, 2)];
    }

    /**
     * @return array<int, array<string, mixed>>  vehicle type id => tariff snapshot
     */
    private function snapshotsByType($vehicleTypes, CarbonImmutable $now): array
    {
        $resolver = app(TariffResolver::class);
        $snapshots = [];

        foreach ($vehicleTypes as $type) {
            $tariff = $resolver->forVehicleType($type, $now) ?? Tariff::query()->where('vehicle_type_id', $type->id)->firstOrFail();
            $snapshots[$type->id] = $tariff->toSnapshot();
        }

        return $snapshots;
    }

    private function weightedHour(): int
    {
        $total = array_sum(self::HOUR_WEIGHTS);
        $pick = mt_rand(1, $total);

        foreach (self::HOUR_WEIGHTS as $hour => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $hour;
            }
        }

        return 0;
    }

    /** @param  \Illuminate\Support\Collection<int, VehicleType>  $types */
    private function pickVehicle($types): VehicleType
    {
        $roll = mt_rand(1, 100);
        $slug = match (true) {
            $roll <= 70 => 'car',
            $roll <= 90 => 'motorbike',
            default => 'van',
        };

        return $types->firstWhere('slug', $slug) ?? $types->first();
    }

    /** Minutes parked: many short stays, some all-day. */
    private function duration(): int
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 40 => mt_rand(15, 90),
            $roll <= 80 => mt_rand(90, 240),
            default => mt_rand(240, 600),
        };
    }

    private function plate(): ?string
    {
        if (mt_rand(1, 100) <= 15) {
            return null;
        }

        return sprintf('%s%03d%s', chr(mt_rand(65, 90)).chr(mt_rand(65, 90)), mt_rand(0, 999), chr(mt_rand(65, 90)).chr(mt_rand(65, 90)));
    }
}
