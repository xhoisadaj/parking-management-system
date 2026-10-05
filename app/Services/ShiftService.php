<?php

namespace App\Services;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    /**
     * The operator's open shift, opened automatically on first activity.
     */
    public function ensureOpen(User $operator, ?CarbonInterface $at = null): Shift
    {
        return DB::transaction(function () use ($operator, $at) {
            // Lock the user row so two parallel actions cannot open two shifts.
            User::query()->whereKey($operator->getKey())->lockForUpdate()->first();

            return Shift::query()
                ->where('user_id', $operator->id)
                ->whereNull('closed_at')
                ->first()
                ?? Shift::create([
                    'user_id' => $operator->id,
                    'work_shift_id' => $operator->work_shift_id,
                    'opened_at' => $at ?? now(),
                ]);
        });
    }

    public function currentOpen(User $operator): ?Shift
    {
        return Shift::query()->where('user_id', $operator->id)->whereNull('closed_at')->first();
    }

    /**
     * Live figures for a shift. Closed shifts use their stored window; open shifts run up to now.
     *
     * @return array{tickets_issued: int, checkouts: int, cash_collected: float}
     */
    public function summary(Shift $shift): array
    {
        $from = $shift->opened_at;
        $to = $shift->closed_at;

        // An open shift has no upper bound yet, so everything since it opened counts.
        $within = fn ($query, string $column) => $to === null
            ? $query->where($column, '>=', $from)
            : $query->whereBetween($column, [$from, $to]);

        $issued = $within(
            ParkingSession::query()->where('entry_user_id', $shift->user_id),
            'entered_at',
        )->count();

        $exits = $within(
            ParkingSession::query()
                ->where('exit_user_id', $shift->user_id)
                ->whereIn('status', [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST]),
            'exited_at',
        );

        return [
            'tickets_issued' => $issued,
            'checkouts' => (clone $exits)->count(),
            'cash_collected' => (float) (clone $exits)->sum('final_price'),
        ];
    }

    /**
     * Closes the operator's open shift and stores its totals. Returns null if none is open.
     */
    public function close(User $operator, ?CarbonInterface $at = null): ?Shift
    {
        $shift = $this->currentOpen($operator);

        if ($shift === null) {
            return null;
        }

        $at ??= now();
        $shift->closed_at = $at;
        $summary = $this->summary($shift);

        $shift->fill([
            'tickets_issued' => $summary['tickets_issued'],
            'checkouts' => $summary['checkouts'],
            'cash_collected' => $summary['cash_collected'],
        ])->save();

        return $shift;
    }

    /**
     * Manager confirms the cash count for a closed shift.
     */
    public function reconcile(Shift $shift, User $manager, float $cashCounted, ?string $note = null, ?CarbonInterface $at = null): Shift
    {
        if ($shift->closed_at === null) {
            throw new ParkingException('Only a closed shift can be reconciled.');
        }

        if ($shift->reconciled_at !== null) {
            throw new ParkingException('This shift has already been reconciled.');
        }

        $shift->update([
            'cash_counted' => round($cashCounted, 2),
            'reconciled_by' => $manager->id,
            'reconciled_at' => $at ?? now(),
            'reconciliation_note' => $note ? trim($note) : null,
        ]);

        return $shift;
    }
}
