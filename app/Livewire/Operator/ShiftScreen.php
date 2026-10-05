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
#[Title('My shift')]
class ShiftScreen extends Component
{
    public ?string $success = null;

    public bool $confirmingClose = false;

    public function mount(ShiftService $shifts): void
    {
        $shifts->ensureOpen(Auth::user());
    }

    public function closeShift(ShiftService $shifts): void
    {
        $closed = $shifts->close(Auth::user());

        $this->confirmingClose = false;

        if ($closed !== null) {
            $this->success = sprintf(
                'Shift closed. %d tickets issued, %d checkouts, %s collected.',
                $closed->tickets_issued,
                $closed->checkouts,
                Format::money($closed->cash_collected),
            );
        }

        $shifts->ensureOpen(Auth::user());
    }

    public function render(ShiftService $shifts)
    {
        $current = $shifts->currentOpen(Auth::user());

        $user = Auth::user()->loadMissing('workShift');
        $workShift = $user->workShift;

        return view('livewire.operator.shift-screen', [
            'workShift' => $workShift,
            'insideHours' => $workShift?->covers(now()->setTimezone(\App\Models\Setting::current()->timezone)),
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
}
