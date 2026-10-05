<?php

namespace App\Livewire\Operator;

use App\Exceptions\ParkingException;
use App\Models\ParkingSession;
use App\Services\CheckoutService;
use App\Services\PriceResult;
use App\Services\Printing\TicketPrinter;
use App\Support\Format;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.operator')]
#[Title('Checkout')]
class CheckoutScreen extends Component
{
    /** Ticket code typed or scanned. Cleared after each lookup. */
    public string $code = '';

    public ?int $sessionId = null;

    /** Empty means "charge the calculated price". Only used when the operator may adjust. */
    public string $finalPrice = '';

    public string $reason = '';

    public ?string $error = null;

    public ?string $success = null;

    public function mount(): void
    {
        Gate::authorize(Permissions::CHECKOUT);
    }

    #[Computed]
    public function session(): ?ParkingSession
    {
        return $this->sessionId === null
            ? null
            : ParkingSession::query()->with('vehicleType')->find($this->sessionId);
    }

    #[Computed]
    public function result(): ?PriceResult
    {
        $session = $this->session;

        return $session === null ? null : app(CheckoutService::class)->calculate($session, now());
    }

    public function lookup(): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $code = strtoupper(trim($this->code));
        $this->code = '';
        $this->success = null;

        if ($code === '') {
            $this->error = 'Scan or type a ticket code.';

            return;
        }

        $session = ParkingSession::query()->where('ticket_code', $code)->first();

        if ($session === null) {
            $this->error = "No ticket with code {$code}.";
            $this->sessionId = null;

            return;
        }

        if ($session->status !== ParkingSession::STATUS_ACTIVE) {
            $this->error = "Ticket {$code} is already closed ({$session->status}).";
            $this->sessionId = null;

            return;
        }

        $this->error = null;
        $this->sessionId = $session->id;
        $this->finalPrice = '';
        $this->reason = '';
    }

    public function confirm(CheckoutService $checkout, TicketPrinter $printer): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $session = $this->session;

        if ($session === null) {
            $this->error = 'Look up a ticket first.';

            return;
        }

        $final = null;

        if (trim($this->finalPrice) !== '') {
            if (! is_numeric($this->finalPrice)) {
                $this->error = 'The final price must be a number.';

                return;
            }

            $final = (float) $this->finalPrice;
        }

        try {
            $closed = $checkout->complete($session, auth()->user(), $final, $this->reason, now());
        } catch (ParkingException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->success = sprintf('%s paid. Total %s.', $closed->ticket_code, Format::money($closed->final_price));
        $this->error = null;
        $this->reset('sessionId', 'finalPrice', 'reason');

        $this->dispatch('print-ticket', url: $printer->printReceipt($closed)->url);
    }

    public function cancel(): void
    {
        $this->reset('sessionId', 'finalPrice', 'reason', 'error');
    }

    public function render()
    {
        return view('livewire.operator.checkout-screen', [
            'canAdjust' => auth()->user()->can(Permissions::ADJUST_PRICE),
            'discountLimit' => auth()->user()->maxDiscountPercent(),
        ]);
    }
}
