<?php

namespace App\Livewire\Operator;

use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\Format;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.operator')]
#[Title('Turni im')]
class ShiftScreen extends Component
{
    public ?string $success = null;

    public ?string $error = null;

    public bool $confirmingClose = false;

    /** Cash physically in the drawer, declared at clock-in. Never defaulted or carried over. */
    public string $openingCash = '';

    /** Cash physically in the drawer, declared at clock-out. Never defaulted or carried over. */
    public string $closingCash = '';

    /**
     * The operator checks in explicitly. No shift opens on its own: the Entry, Checkout and Lost
     * ticket screens are blocked (see EnsureShiftIsOpen) until this is called.
     *
     * Two rules the admin can turn off entirely in Cilësimet e parkimit (Setting::current()):
     *  - enforce_work_shift_hours: refuses to open outside the operator's assigned work shift
     *    hours, when they have one. An operator with no work shift assigned has no hours to check
     *    against, so this never blocks them regardless of the setting.
     *  - require_cash_declaration: the operator must declare what's in the drawer — even "0"
     *    counts, blank doesn't — before a shift can open. Off means no field, no check at all.
     */
    public function startShift(ShiftService $shifts): void
    {
        $user = Auth::user()->loadMissing('workShift');
        $workShift = $user->workShift;
        $setting = \App\Models\Setting::current();

        if ($setting->enforce_work_shift_hours && $workShift !== null) {
            $now = now()->setTimezone($setting->timezone);

            if (! $workShift->covers($now)) {
                $this->error = "Nuk mund ta filloni turnin tani — turni juaj i caktuar është {$workShift->hoursLabel()}.";

                return;
            }
        }

        $amount = null;

        if ($setting->require_cash_declaration) {
            $amount = $this->parseNumber($this->openingCash);

            if ($amount === null) {
                $this->error = 'Shkruani sa para ka në arkë tani — edhe nëse është 0.';

                return;
            }

            if ($amount < 0) {
                $this->error = 'Paraja në arkë nuk mund të jetë negative.';

                return;
            }
        }

        $shifts->ensureOpen($user, null, $amount);
        $this->reset('openingCash');
        $this->error = null;
        $this->success = null;
    }

    /**
     * The operator declares what they counted in the drawer before the shift can close at all —
     * same "even 0 counts, blank doesn't" rule as clocking in, and the same
     * require_cash_declaration setting turns it off entirely. This is their own count, kept
     * separate from cash_counted (the manager's independent count at reconciliation), so a
     * mismatch between the two is visible rather than one silently overwriting the other.
     */
    public function closeShift(ShiftService $shifts): void
    {
        $amount = null;

        if (\App\Models\Setting::current()->require_cash_declaration) {
            $amount = $this->parseNumber($this->closingCash);

            if ($amount === null) {
                $this->error = 'Shkruani sa para ka në arkë tani — edhe nëse është 0.';

                return;
            }

            if ($amount < 0) {
                $this->error = 'Paraja në arkë nuk mund të jetë negative.';

                return;
            }
        }

        $closed = $shifts->close(Auth::user(), null, $amount);

        $this->confirmingClose = false;
        $this->reset('closingCash');
        $this->error = null;

        if ($closed !== null) {
            $message = sprintf(
                'Turni u mbyll. %d biletë të lëshuara, %d arkëtime, %s të arkëtuara.',
                $closed->tickets_issued,
                $closed->checkouts,
                Format::money($closed->cash_collected),
            );

            if ($closed->closing_cash_declared !== null) {
                $message .= ' '.Format::money($closed->closing_cash_declared).' të deklaruara në arkë.';
            }

            $this->success = $message;
        }
    }

    public function render(ShiftService $shifts)
    {
        $current = $shifts->currentOpen(Auth::user());

        $user = Auth::user()->loadMissing('workShift');
        $workShift = $user->workShift;
        $setting = \App\Models\Setting::current();

        return view('livewire.operator.shift-screen', [
            'workShift' => $workShift,
            'insideHours' => $workShift?->covers(now()->setTimezone($setting->timezone)),
            'enforceHours' => $setting->enforce_work_shift_hours,
            'requireCash' => $setting->require_cash_declaration,
            'current' => $current,
            'summary' => $current ? $shifts->summary($current) : null,
            'recent' => Shift::query()
                ->where('user_id', Auth::id())
                ->whereNotNull('closed_at')
                ->orderByDesc('closed_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /** Accepts "1,250.50" or "1250,50". Returns null when the text is not a number (including blank). */
    private function parseNumber(string $value): ?float
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = str_contains($value, ',') && ! str_contains($value, '.')
            ? str_replace(',', '.', $value)
            : str_replace(',', '', $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
