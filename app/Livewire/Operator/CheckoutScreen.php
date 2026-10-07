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
#[Title('Arkëtimi')]
class CheckoutScreen extends Component
{
    /** Ticket code typed or scanned. Cleared after each lookup. */
    public string $code = '';

    public ?int $sessionId = null;

    /** Empty means "charge the calculated price". Only used when the operator may adjust. */
    public string $finalPrice = '';

    /** Cash the customer handed over. Empty means no cash was counted. */
    public string $amountReceived = '';

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

    /** The price that will be charged, taking the operator's typed price into account. */
    #[Computed]
    public function chargeable(): ?float
    {
        if ($this->result === null) {
            return null;
        }

        return $this->parseNumber($this->finalPrice) ?? $this->result->total();
    }

    /**
     * Live cash feedback. Null until an amount is typed.
     *
     * @return array{change: float, short: bool}|null
     */
    #[Computed]
    public function cash(): ?array
    {
        $received = $this->parseNumber($this->amountReceived);

        if ($received === null || $this->chargeable === null) {
            return null;
        }

        $difference = round($received - $this->chargeable, 2);

        return [
            'change' => max(0, $difference),
            'short' => $difference < 0,
            'short_by' => abs($difference),
        ];
    }

    /** Whether the reason field must be filled in for the price the operator typed. */
    #[Computed]
    public function reasonRequired(): bool
    {
        $typed = $this->parseNumber($this->finalPrice);

        if ($typed === null || $this->result === null) {
            return false;
        }

        return app(CheckoutService::class)->reasonRequired($this->result->total(), $typed);
    }

    public function lookup(): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $code = strtoupper(trim($this->code));
        $this->code = '';
        $this->success = null;

        if ($code === '') {
            $this->error = 'Skanoni ose shkruani kodin e biletës.';

            return;
        }

        $session = ParkingSession::query()->where('ticket_code', $code)->first();

        if ($session === null) {
            $this->error = "Nuk ka biletë me kodin {$code}.";
            $this->sessionId = null;

            return;
        }

        if ($session->status !== ParkingSession::STATUS_ACTIVE) {
            $statusLabel = ParkingSession::statusLabel($session->status);
            $this->error = "Bileta {$code} është mbyllur tashmë ({$statusLabel}).";
            $this->sessionId = null;

            return;
        }

        $this->error = null;
        $this->sessionId = $session->id;
        $this->reset('finalPrice', 'amountReceived', 'reason');
    }

    public function confirm(CheckoutService $checkout, TicketPrinter $printer): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $session = $this->session;

        if ($session === null) {
            $this->error = 'Kërkoni një biletë më parë.';

            return;
        }

        if (trim($this->finalPrice) !== '' && $this->parseNumber($this->finalPrice) === null) {
            $this->error = 'Çmimi duhet të jetë numër.';

            return;
        }

        if (trim($this->amountReceived) !== '' && $this->parseNumber($this->amountReceived) === null) {
            $this->error = 'Shuma e marrë duhet të jetë numër.';

            return;
        }

        try {
            $closed = $checkout->complete(
                $session,
                auth()->user(),
                $this->parseNumber($this->finalPrice),
                trim($this->reason) === '' ? null : $this->reason,
                now(),
                $this->parseNumber($this->amountReceived),
            );
        } catch (ParkingException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $message = sprintf('%s u paguan. Totali %s.', $closed->ticket_code, Format::money($closed->final_price));

        if ($closed->change_given !== null && (float) $closed->change_given > 0) {
            $message .= ' Kthe kusurin '.Format::money($closed->change_given).'.';
        }

        $this->success = $message;
        $this->error = null;
        $this->reset('sessionId', 'finalPrice', 'amountReceived', 'reason');

        $this->dispatch('print-ticket', url: $printer->printReceipt($closed)->url);
    }

    public function cancel(): void
    {
        $this->reset('sessionId', 'finalPrice', 'amountReceived', 'reason', 'error');
    }

    public function render()
    {
        return view('livewire.operator.checkout-screen', [
            'canAdjust' => auth()->user()->can(Permissions::ADJUST_PRICE),
            'discountLimit' => auth()->user()->maxDiscountPercent(),
        ]);
    }

    /** Accepts "1,250.50" or "1250,50". Returns null when the text is not a number. */
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
