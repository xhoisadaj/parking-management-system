<?php

namespace App\Livewire\Operator;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\VehicleType;
use App\Services\ShiftService;
use App\Services\TicketEditor;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Biletat e mia": an operator's own issue history — the tickets where they were the entry
 * operator, filtered to today, the current shift, or the last 7 days — with the ability to
 * correct one still active (plate, vehicle type, entry time, and a price override if they also
 * hold adjust_own_ticket_price). Gated by manage_own_tickets, a permission an admin grants per person (see
 * Permissions::individuallyGrantablePermissions()) — it does nothing on its own otherwise.
 *
 * This is deliberately scoped to the operator's own tickets, not every active ticket — for
 * anyone who needs that wider view, Biletë e humbur already lists every active ticket to search
 * and act on (lost fee, void), and Menaxhimi's Biletat page covers every ticket for whoever holds
 * edit_ticket.
 */
#[Layout('components.layouts.operator')]
#[Title('Biletat e mia')]
class MyTicketsScreen extends Component
{
    public string $period = 'today';

    /** Locked: only select() may set it, after checking the ticket is this operator's own. */
    #[Locked]
    public ?int $selectedId = null;

    public string $vehicleTypeId = '';

    public string $plate = '';

    public string $enteredAt = '';

    public string $priceOverride = '';

    public string $priceOverrideReason = '';

    public ?string $error = null;

    public ?string $success = null;

    public function mount(): void
    {
        Gate::authorize(Permissions::MANAGE_OWN_TICKETS);
    }

    /** @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface} */
    private function range(): array
    {
        $timezone = Setting::current()->timezone;
        $now = now()->setTimezone($timezone);

        return match ($this->period) {
            'shift' => [
                app(ShiftService::class)->currentOpen(Auth::user())?->opened_at ?? $now->copy()->startOfDay(),
                $now,
            ],
            'week' => [$now->copy()->subDays(7), $now],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    #[Computed]
    public function tickets()
    {
        [$from, $to] = $this->range();

        return ParkingSession::query()
            ->where('entry_user_id', Auth::id())
            ->whereBetween('entered_at', [$from, $to])
            ->with('vehicleType')
            ->orderByDesc('entered_at')
            ->limit(100)
            ->get();
    }

    /**
     * The ticket being edited — re-checked as this operator's own and still active on every
     * request, so save() never acts on a ticket select() wouldn't have opened.
     */
    #[Computed]
    public function selected(): ?ParkingSession
    {
        return $this->selectedId === null
            ? null
            : $this->ownActiveTickets()->with('vehicleType')->find($this->selectedId);
    }

    /** Only an active ticket this operator themselves issued can be opened for editing. */
    public function select(int $id): void
    {
        $session = $this->ownActiveTickets()->find($id);

        if ($session === null) {
            $this->error = 'Kjo biletë nuk është më aktive.';

            return;
        }

        $this->selectedId = $session->id;
        $this->vehicleTypeId = (string) $session->vehicle_type_id;
        $this->plate = (string) $session->plate;
        $this->enteredAt = $session->entered_at->format('Y-m-d\TH:i');
        $this->priceOverride = $session->final_price !== null ? (string) $session->final_price : '';
        $this->priceOverrideReason = (string) $session->adjustment_reason;
        $this->error = null;
        $this->success = null;
    }

    public function cancel(): void
    {
        $this->reset('selectedId', 'vehicleTypeId', 'plate', 'enteredAt', 'priceOverride', 'priceOverrideReason', 'error');
    }

    public function save(TicketEditor $editor): void
    {
        $session = $this->selected;

        if ($session === null) {
            $this->error = $this->selectedId === null ? 'Zgjidhni një biletë më parë.' : 'Kjo biletë nuk është më aktive.';

            return;
        }

        $vehicleType = VehicleType::query()->find($this->vehicleTypeId);

        if ($vehicleType === null) {
            $this->error = 'Zgjidhni llojin e mjetit.';

            return;
        }

        $enteredAt = $this->parseDateTime($this->enteredAt);

        if ($enteredAt === null) {
            $this->error = 'Ora e hyrjes nuk është e vlefshme.';

            return;
        }

        $override = $this->parseNumber($this->priceOverride);

        if (trim($this->priceOverride) !== '' && $override === null) {
            $this->error = 'Çmimi i personalizuar duhet të jetë numër.';

            return;
        }

        try {
            $editor->edit(
                $session,
                Auth::user(),
                $vehicleType,
                $this->plate,
                $enteredAt,
                $override,
                trim($this->priceOverrideReason) === '' ? null : $this->priceOverrideReason,
                Permissions::ADJUST_OWN_TICKET_PRICE,
            );
        } catch (ParkingException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->success = $session->ticket_code.' u përditësua.';
        $this->cancel();
    }

    public function render()
    {
        return view('livewire.operator.my-tickets-screen', [
            'canAdjustPrice' => Auth::user()->can(Permissions::ADJUST_OWN_TICKET_PRICE),
            'vehicleTypes' => VehicleType::query()->orderBy('sort_order')->get(),
        ]);
    }

    private function ownActiveTickets()
    {
        return ParkingSession::query()
            ->where('entry_user_id', Auth::id())
            ->where('status', ParkingSession::STATUS_ACTIVE);
    }

    private function parseDateTime(string $value): ?\Carbon\CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($value, Setting::current()->timezone);
        } catch (\Throwable) {
            return null;
        }
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
