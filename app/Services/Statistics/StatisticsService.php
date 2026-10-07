<?php

namespace App\Services\Statistics;

use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CapacityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * All dashboard and export figures. Revenue is counted by exit time. Entries are counted by entry time.
 * Voided tickets are excluded everywhere.
 */
class StatisticsService
{
    public function __construct(private readonly CapacityService $capacity) {}

    /** Paid and lost tickets closed in the range. */
    private function closedBetween(CarbonInterface $from, CarbonInterface $to)
    {
        return ParkingSession::query()
            ->whereIn('parking_sessions.status', [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST])
            ->whereBetween('parking_sessions.exited_at', [$from, $to]);
    }

    public function revenue(CarbonInterface $from, CarbonInterface $to): float
    {
        return round((float) $this->closedBetween($from, $to)->sum('parking_sessions.final_price'), 2);
    }

    /**
     * @return list<array{vehicle: string, tickets: int, revenue: float}>
     */
    public function revenueByVehicleType(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->closedBetween($from, $to)
            ->join('vehicle_types', 'vehicle_types.id', '=', 'parking_sessions.vehicle_type_id')
            ->groupBy('vehicle_types.id', 'vehicle_types.name', 'vehicle_types.sort_order')
            ->orderBy('vehicle_types.sort_order')
            ->get([
                'vehicle_types.name as vehicle',
                DB::raw('COUNT(*) as tickets'),
                DB::raw('COALESCE(SUM(parking_sessions.final_price), 0) as revenue'),
            ])
            ->map(fn ($row) => [
                'vehicle' => $row->vehicle,
                'tickets' => (int) $row->tickets,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Calculated vs actual for paid tickets. Lost tickets are reported separately because
     * their fee is not a price adjustment.
     *
     * @return array{calculated: float, actual: float, impact: float, adjustments: int, adjustment_value: float, lost_tickets: int, lost_fees: float}
     */
    public function calculatedVsActual(CarbonInterface $from, CarbonInterface $to): array
    {
        $paid = ParkingSession::query()
            ->where('status', ParkingSession::STATUS_PAID)
            ->whereBetween('exited_at', [$from, $to]);

        $calculated = round((float) (clone $paid)->sum('calculated_price'), 2);
        $actual = round((float) (clone $paid)->sum('final_price'), 2);

        $adjusted = (clone $paid)->whereNotNull('adjusted_by');

        $lost = ParkingSession::query()
            ->where('status', ParkingSession::STATUS_LOST)
            ->whereBetween('exited_at', [$from, $to]);

        return [
            'calculated' => $calculated,
            'actual' => $actual,
            'impact' => round($actual - $calculated, 2),
            'adjustments' => (clone $adjusted)->count(),
            'adjustment_value' => round((float) (clone $adjusted)->sum(DB::raw('final_price - calculated_price')), 2),
            'lost_tickets' => (clone $lost)->count(),
            'lost_fees' => round((float) (clone $lost)->sum('final_price'), 2),
        ];
    }

    public function averageStayMinutes(CarbonInterface $from, CarbonInterface $to): ?float
    {
        $avg = ParkingSession::query()
            ->where('status', ParkingSession::STATUS_PAID)
            ->whereBetween('exited_at', [$from, $to])
            ->avg('duration_minutes');

        return $avg === null ? null : round((float) $avg, 1);
    }

    /**
     * Totals for one day (or any period), optionally for one operator.
     * Tickets released are counted by entry time. Closed tickets and revenue are counted by exit time.
     * With an operator, entries count where they were the entry operator and exits where they were the exit operator.
     *
     * @return array{issued: int, still_parked: int, voided: int, closed: int, paid: int, lost: int, revenue: float}
     */
    public function daySummary(CarbonInterface $from, CarbonInterface $to, ?int $operatorId = null): array
    {
        $entered = ParkingSession::query()->whereBetween('parking_sessions.entered_at', [$from, $to]);
        $closed = $this->closedBetween($from, $to);

        if ($operatorId !== null) {
            $entered->where('parking_sessions.entry_user_id', $operatorId);
            $closed->where('parking_sessions.exit_user_id', $operatorId);
        }

        return [
            'issued' => (clone $entered)->count(),
            'still_parked' => (clone $entered)->where('parking_sessions.status', ParkingSession::STATUS_ACTIVE)->count(),
            'voided' => (clone $entered)->where('parking_sessions.status', ParkingSession::STATUS_VOID)->count(),
            'closed' => (clone $closed)->count(),
            'paid' => (clone $closed)->where('parking_sessions.status', ParkingSession::STATUS_PAID)->count(),
            'lost' => (clone $closed)->where('parking_sessions.status', ParkingSession::STATUS_LOST)->count(),
            'revenue' => round((float) (clone $closed)->sum('parking_sessions.final_price'), 2),
        ];
    }

    /**
     * Per-operator figures. Issued counts entries by that operator. Checkouts, revenue and
     * adjustments count the exits by that operator. Adjustment value is net (final − calculated).
     *
     * @return list<array{user: string, issued: int, checkouts: int, revenue: float, adjustments: int, adjustment_value: float}>
     */
    public function perUser(CarbonInterface $from, CarbonInterface $to): array
    {
        $issued = ParkingSession::query()
            ->whereBetween('entered_at', [$from, $to])
            ->where('status', '!=', ParkingSession::STATUS_VOID)
            ->groupBy('entry_user_id')
            ->selectRaw('entry_user_id as user_id, COUNT(*) as issued')
            ->pluck('issued', 'user_id');

        $exits = $this->closedBetween($from, $to)
            ->whereNotNull('exit_user_id')
            ->groupBy('exit_user_id')
            ->selectRaw('exit_user_id as user_id, COUNT(*) as checkouts, COALESCE(SUM(final_price), 0) as revenue')
            ->get()
            ->keyBy('user_id');

        $adjustments = ParkingSession::query()
            ->where('status', ParkingSession::STATUS_PAID)
            ->whereNotNull('adjusted_by')
            ->whereBetween('exited_at', [$from, $to])
            ->groupBy('adjusted_by')
            ->selectRaw('adjusted_by as user_id, COUNT(*) as adjustments, COALESCE(SUM(final_price - calculated_price), 0) as value')
            ->get()
            ->keyBy('user_id');

        $ids = collect($issued->keys())
            ->merge($exits->keys())
            ->merge($adjustments->keys())
            ->unique()
            ->filter()
            ->values();

        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $ids
            ->map(fn ($id) => [
                'user' => $names[$id] ?? 'I panjohur',
                'issued' => (int) ($issued[$id] ?? 0),
                'checkouts' => (int) ($exits[$id]->checkouts ?? 0),
                'revenue' => round((float) ($exits[$id]->revenue ?? 0), 2),
                'adjustments' => (int) ($adjustments[$id]->adjustments ?? 0),
                'adjustment_value' => round((float) ($adjustments[$id]->value ?? 0), 2),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Average occupied spots for each weekday and hour, sampled on the hour within the range.
     * Only hours that have already happened are sampled.
     *
     * @return array{cells: array<int, array<int, ?float>>, max: float}  cells[isoWeekday 1-7][hour 0-23]
     */
    public function occupancyHeatmap(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        $tz = Setting::current()->timezone;
        $now = CarbonImmutable::now();
        $end = $to->lessThan($now) ? $to : $now;

        $timeline = $this->occupancyTimeline($from, $end);

        $sums = [];
        $counts = [];

        foreach ($this->hourSamples($from, $end) as $sample) {
            $local = $sample->setTimezone($tz);
            $weekday = $local->dayOfWeekIso;
            $hour = $local->hour;

            $sums[$weekday][$hour] = ($sums[$weekday][$hour] ?? 0) + $timeline->at($sample->getTimestamp());
            $counts[$weekday][$hour] = ($counts[$weekday][$hour] ?? 0) + 1;
        }

        $cells = [];
        $max = 0.0;

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $n = $counts[$weekday][$hour] ?? 0;
                $avg = $n > 0 ? round($sums[$weekday][$hour] / $n, 2) : null;
                $cells[$weekday][$hour] = $avg;
                $max = max($max, $avg ?? 0);
            }
        }

        return ['cells' => $cells, 'max' => $max];
    }

    /**
     * Current occupancy per vehicle type, and the pool as a whole.
     *
     * @return array{types: list<array{name: string, vehicles: int, free: int, dedicated: ?int}>, occupied_spots: float, total_spots: float, free_spots: float}
     */
    public function liveOccupancy(): array
    {
        $types = VehicleType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (VehicleType $type) => [
                'name' => $type->name,
                'vehicles' => $this->capacity->activeVehicleCount($type),
                'free' => $this->capacity->freeVehiclesFor($type),
                'dedicated' => $type->dedicated_capacity,
            ])
            ->all();

        $total = $this->capacity->totalCapacity();
        $occupied = $this->capacity->occupiedSpots();

        return [
            'types' => $types,
            'occupied_spots' => round($occupied, 2),
            'total_spots' => $total,
            'free_spots' => round(max(0, $total - $occupied), 2),
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function hourSamples(CarbonInterface $from, CarbonInterface $to): array
    {
        $samples = [];

        for ($t = CarbonImmutable::instance($from)->startOfHour(); $t->lessThanOrEqualTo($to); $t = $t->addHour()) {
            $samples[] = $t;
        }

        return $samples;
    }

    /**
     * Sweep over entry/exit events to get occupied spots at any instant without a query per sample.
     */
    private function occupancyTimeline(CarbonInterface $from, CarbonInterface $to): OccupancyTimeline
    {
        $tz = config('app.timezone');
        $now = CarbonImmutable::now();

        $rows = DB::table('parking_sessions')
            ->join('vehicle_types', 'vehicle_types.id', '=', 'parking_sessions.vehicle_type_id')
            ->where('parking_sessions.status', '!=', ParkingSession::STATUS_VOID)
            ->where('parking_sessions.entered_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('parking_sessions.exited_at')->orWhere('parking_sessions.exited_at', '>=', $from))
            ->get(['parking_sessions.entered_at', 'parking_sessions.exited_at', 'vehicle_types.spots_used']);

        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $events = [];

        foreach ($rows as $row) {
            $start = max(CarbonImmutable::parse($row->entered_at, $tz)->getTimestamp(), $fromTs);
            $end = $row->exited_at
                ? CarbonImmutable::parse($row->exited_at, $tz)->getTimestamp()
                : $now->getTimestamp();
            $end = min($end, $toTs + 1);

            if ($end <= $start) {
                continue;
            }

            $spots = (float) $row->spots_used;
            $events[] = [$start, $spots];
            $events[] = [$end, -$spots];
        }

        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);

        return new OccupancyTimeline($events);
    }
}
