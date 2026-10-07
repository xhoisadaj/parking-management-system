<?php

namespace App\Livewire\Operator;

use App\Exceptions\ParkingException;
use App\Models\OpeningHour;
use App\Models\ParkingSession;
use App\Models\Setting;
use App\Models\VehicleType;
use App\Services\CapacityService;
use App\Services\Printing\TicketPrinter;
use App\Services\TicketIssuer;
use App\Support\Format;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.operator')]
#[Title('Hyrja')]
class EntryScreen extends Component
{
    public string $plate = '';

    public ?string $error = null;

    /** @var array{code: string, vehicle: string, entered: string}|null */
    public ?array $issued = null;

    public function mount(): void
    {
        Gate::authorize(Permissions::ISSUE_TICKET);
    }

    public function issue(int $vehicleTypeId, TicketIssuer $issuer, TicketPrinter $printer): void
    {
        Gate::authorize(Permissions::ISSUE_TICKET);

        $vehicleType = VehicleType::query()->findOrFail($vehicleTypeId);

        try {
            $session = $issuer->issue($vehicleType, $this->plate, auth()->user());
        } catch (ParkingException $e) {
            $this->error = $e->getMessage();
            $this->issued = null;

            return;
        }

        $this->error = null;
        $this->plate = '';
        $this->issued = [
            'code' => $session->ticket_code,
            'vehicle' => $vehicleType->name,
            'entered' => Format::dateTime($session->entered_at),
        ];

        $this->dispatch('print-ticket', url: $printer->printEntry($session)->url);
    }

    public function reprint(TicketPrinter $printer): void
    {
        Gate::authorize(Permissions::ISSUE_TICKET);

        if ($this->issued === null) {
            return;
        }

        $session = ParkingSession::query()->where('ticket_code', $this->issued['code'])->first();

        if ($session) {
            $this->dispatch('print-ticket', url: $printer->printEntry($session)->url);
        }
    }

    public function render(CapacityService $capacity)
    {
        $now = now()->setTimezone(Setting::current()->timezone);
        $todayHours = OpeningHour::query()->where('weekday', $now->dayOfWeek)->first();

        $types = VehicleType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (VehicleType $type) => [
                'model' => $type,
                'free' => $capacity->freeVehiclesFor($type),
            ]);

        return view('livewire.operator.entry-screen', [
            'types' => $types,
            'lotOpen' => $todayHours?->isOpenAt($now) ?? true,
            'lotClosedToday' => $todayHours?->is_closed ?? false,
            'poolFree' => max(0, $capacity->totalCapacity() - $capacity->occupiedSpots()),
            'poolTotal' => $capacity->totalCapacity(),
        ]);
    }
}
