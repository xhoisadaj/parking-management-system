<?php

namespace App\Services;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    /**
     * The operator's open shift. If one is already open it's returned as-is — $openingCash is
     * only used the moment a new shift is actually created. Called without it from places that
     * merely need a shift to exist (issuing a ticket, checking one out), not from the explicit
     * "Fillo turnin" clock-in action, which always supplies it — see ShiftScreen::startShift().
     * A shift opened the other way (no declared float) keeps opening_cash null, which the
     * reconciliation screens show plainly rather than assuming it was zero.
     */
    public function ensureOpen(User $operator, ?CarbonInterface $at = null, ?float $openingCash = null): Shift
    {
        return DB::transaction(function () use ($operator, $at, $openingCash) {
            // Lock the user row so two parallel actions cannot open two shifts.
            User::query()->whereKey($operator->getKey())->lockForUpdate()->first();

            return Shift::query()
                ->where('user_id', $operator->id)
                ->whereNull('closed_at')
                ->first()
                ?? Shift::create([
                    'user_id' => $operator->id,
                    'opening_cash' => $openingCash !== null ? round($openingCash, 2) : null,
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
     * Live figures for a shift: the tickets issued in it and the checkouts taken in it, by the
     * shift each ticket recorded when it was issued or closed (entry_shift_id / exit_shift_id) —
     * not by time window, which can't tell two shifts apart within the same second.
     *
     * @return array{tickets_issued: int, checkouts: int, cash_collected: float}
     */
    public function summary(Shift $shift): array
    {
        $exits = $this->checkoutsIn($shift);

        return [
            'tickets_issued' => ParkingSession::query()->where('entry_shift_id', $shift->id)->count(),
            'checkouts' => (clone $exits)->count(),
            'cash_collected' => (float) (clone $exits)->sum('final_price'),
        ];
    }

    /**
     * The paid and lost tickets behind a shift's "cash collected" — what summary() sums and the
     * reconciliation screen itemizes, so the two always match ticket by ticket.
     */
    public function checkoutsIn(Shift $shift): Builder
    {
        return ParkingSession::query()
            ->where('exit_shift_id', $shift->id)
            ->whereIn('status', [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST]);
    }

    /**
     * Closes the operator's open shift and stores its totals. Returns null if none is open.
     *
     * $closingCashDeclared is what the operator themselves counted in the drawer before closing —
     * required by ShiftScreen::closeShift() (even "0" counts, blank doesn't), same as opening_cash
     * at clock-in. Optional here, like $openingCash on ensureOpen(), since this is the only
     * business rule that needs it; nothing else closes a shift.
     */
    public function close(User $operator, ?CarbonInterface $at = null, ?float $closingCashDeclared = null): ?Shift
    {
        return DB::transaction(function () use ($operator, $at, $closingCashDeclared) {
            // The same user-row lock ensureOpen() takes. A ticket issue or checkout holds it until
            // it commits, so this waits for one in progress and then counts it — and one starting
            // after this commits opens a new shift instead of landing in this one uncounted.
            User::query()->whereKey($operator->getKey())->lockForUpdate()->first();

            $shift = $this->currentOpen($operator);

            if ($shift === null) {
                return null;
            }

            $summary = $this->summary($shift);

            $shift->fill([
                'closed_at' => $at ?? now(),
                'tickets_issued' => $summary['tickets_issued'],
                'checkouts' => $summary['checkouts'],
                'cash_collected' => $summary['cash_collected'],
                'closing_cash_declared' => $closingCashDeclared !== null ? round($closingCashDeclared, 2) : null,
            ])->save();

            return $shift;
        });
    }

    /**
     * Manager confirms the cash count for a closed shift.
     */
    public function reconcile(Shift $shift, User $manager, float $cashCounted, ?string $note = null, ?CarbonInterface $at = null): Shift
    {
        return DB::transaction(function () use ($shift, $manager, $cashCounted, $note, $at) {
            // Re-read under a lock, so two managers confirming the same shift at once can't both
            // pass the "not reconciled yet" check.
            $shift = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            if ($shift->closed_at === null) {
                throw new ParkingException('Vetëm një turn i mbyllur mund të verifikohet.');
            }

            if ($shift->reconciled_at !== null) {
                throw new ParkingException('Ky turn është verifikuar tashmë.');
            }

            $shift->update([
                'cash_counted' => round($cashCounted, 2),
                'reconciled_by' => $manager->id,
                'reconciled_at' => $at ?? now(),
                'reconciliation_note' => $note ? trim($note) : null,
            ]);

            return $shift;
        });
    }
}
