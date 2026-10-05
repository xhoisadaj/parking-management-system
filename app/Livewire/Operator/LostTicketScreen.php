<?php

namespace App\Livewire\Operator;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\Setting;
use App\Services\LostTicketService;
use App\Services\Printing\TicketPrinter;
use App\Services\VoidService;
use App\Support\Format;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.operator')]
#[Title('Lost ticket')]
class LostTicketScreen extends Component
{
    public string $search = '';

    public ?int $selectedId = null;

    public string $voidReason = '';

    /** Cash the customer handed over for the lost-ticket fee. Empty means not counted. */
    public string $amountReceived = '';

    public ?string $error = null;

    public ?string $success = null;

    public function mount(): void
    {
        Gate::authorize(Permissions::CHECKOUT);
    }

    #[Computed]
    public function tickets()
    {
        $term = trim($this->search);

        return ParkingSession::query()
            ->active()
            ->with('vehicleType')
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($q) use ($term) {
                    $q->where('plate', 'like', '%'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term)).'%')
                        ->orWhere('ticket_code', strtoupper($term));
                });
            })
            ->orderByDesc('entered_at')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function selected(): ?ParkingSession
    {
        return $this->selectedId === null ? null : ParkingSession::query()->with('vehicleType')->find($this->selectedId);
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->voidReason = '';
        $this->amountReceived = '';
        $this->error = null;
        $this->success = null;
    }

    public function clearSelection(): void
    {
        $this->reset('selectedId', 'voidReason', 'error');
    }

    public function markLost(LostTicketService $lost, TicketPrinter $printer): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $session = $this->selected;

        if ($session === null) {
            $this->error = 'Select a ticket first.';

            return;
        }

        try {
            $closed = $lost->markLost($session, auth()->user(), null, self::number($this->amountReceived));
        } catch (ParkingException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $fee = Format::money(Setting::current()->lost_ticket_fee);
        $this->success = "{$closed->ticket_code} closed as lost. Fee {$fee}.";
        $this->clearSelection();

        $this->dispatch('print-ticket', url: $printer->printReceipt($closed)->url);
    }

    public function voidTicket(VoidService $voids): void
    {
        Gate::authorize(Permissions::VOID_TICKET);

        $session = $this->selected;

        if ($session === null) {
            $this->error = 'Select a ticket first.';

            return;
        }

        try {
            $voided = $voids->void($session, auth()->user(), $this->voidReason);
        } catch (ParkingException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->success = "{$voided->ticket_code} voided. Spot released.";
        $this->clearSelection();
    }

    public function render()
    {
        return view('livewire.operator.lost-ticket-screen', [
            'canVoid' => auth()->user()->can(Permissions::VOID_TICKET),
        ]);
    }

    private static function number(string $value): ?float
    {
        return trim($value) !== '' && is_numeric(str_replace(',', '.', $value)) ? (float) str_replace(',', '.', $value) : null;
    }
}
