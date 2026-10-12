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

    /** Cash the customer handed over. Required whenever there is a price to pay. */
    public string $amountReceived = '';

    public string $reason = '';

    /** Typed next to the discount quick buttons. Only used when the operator clicks "Apliko". */
    public string $customDiscountPercent = '';

    /** Preset discount buttons shown to an operator who may adjust the price (subject to their role's limit). */
    public const DISCOUNT_PRESETS = [10.0, 20.0];

    /** Reasons offered as one-tap chips next to the reason field. */
    public const REASON_SUGGESTIONS = [
        'Negocim me klientin',
        'Klient i rregullt',
        'Dëmtim/gabim në biletë',
        'Kërkesë e menaxherit',
        'Vonesë e justifikuar',
    ];

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

    /**
     * Active tickets matching what's typed so far, by exact ticket code or a plate that contains
     * it. Drives the pick-one list shown under the search field whenever a plate search turns up
     * more than one ticket (or the operator hasn't pressed Enter yet).
     */
    #[Computed]
    public function results()
    {
        $term = trim($this->code);

        if ($term === '') {
            return collect();
        }

        $normalizedCode = strtoupper($term);
        $plateDigits = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term));

        return ParkingSession::query()
            ->active()
            ->with('vehicleType')
            ->where(function ($query) use ($normalizedCode, $plateDigits) {
                $query->where('ticket_code', $normalizedCode);

                if (strlen($plateDigits) >= 2) {
                    $query->orWhere('plate', 'like', '%'.$plateDigits.'%');
                }
            })
            ->orderByDesc('entered_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function result(): ?PriceResult
    {
        $session = $this->session;

        return $session === null ? null : app(CheckoutService::class)->calculate($session, now());
    }

    /**
     * A price already set on this ticket ahead of checkout — either an admin/manager from
     * Menaxhimi's Biletat page, or the issuing operator themselves from Biletat e mia (see
     * TicketEditor::applyPriceOverride()). Null for an ordinary ticket. Shown to every operator at
     * checkout regardless of their own adjust-price permission, since they didn't decide this
     * number — $session->adjustedBy did, and already gave a reason for it (see the blade view,
     * which names them instead of assuming "the administrator").
     */
    #[Computed]
    public function presetPrice(): ?float
    {
        return $this->session?->final_price !== null ? (float) $this->session->final_price : null;
    }

    /** The price that will be charged: the operator's typed price, else an admin's preset, else the calculated one. */
    #[Computed]
    public function chargeable(): ?float
    {
        if ($this->result === null) {
            return null;
        }

        return $this->parseNumber($this->finalPrice) ?? $this->presetPrice ?? $this->result->total();
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

    /** True once something was typed that isn't a number — used to flag the field red right away. */
    #[Computed]
    public function finalPriceInvalid(): bool
    {
        return trim($this->finalPrice) !== '' && $this->parseNumber($this->finalPrice) === null;
    }

    /** True once something was typed that isn't a number — used to flag the field red right away. */
    #[Computed]
    public function amountReceivedInvalid(): bool
    {
        return trim($this->amountReceived) !== '' && $this->parseNumber($this->amountReceived) === null;
    }

    /**
     * Live warning for the exact case CheckoutService will refuse at confirm time: a discount
     * beyond the operator's role limit. Shown (and the button disabled) before they even submit,
     * instead of letting them find out only after a round trip.
     */
    #[Computed]
    public function discountExceededMessage(): ?string
    {
        $typed = $this->parseNumber($this->finalPrice);

        if ($typed === null || $this->result === null) {
            return null;
        }

        $calculated = $this->result->total();

        if ($typed >= $calculated || $calculated <= 0) {
            return null;
        }

        $limit = auth()->user()->maxDiscountPercent();

        if ($limit === null) {
            return null;
        }

        $discount = round(($calculated - $typed) / $calculated * 100, 2);

        if ($discount <= $limit) {
            return null;
        }

        return sprintf(
            'Zbritja prej %s%% është mbi limitin tuaj prej %s%%.',
            Format::amount($discount, 2, true),
            Format::amount($limit, 2, true),
        );
    }

    /** Whether the form has something that would make confirm/quickPay fail right away. */
    #[Computed]
    public function blockedFromConfirming(): bool
    {
        return $this->finalPriceInvalid
            || $this->amountReceivedInvalid
            || $this->discountExceededMessage !== null
            || ($this->reasonRequired && trim($this->reason) === '');
    }

    /**
     * Discount presets the operator's role is actually allowed to use, so a button that would just
     * be refused at confirm time is never shown.
     *
     * @return list<float>
     */
    #[Computed]
    public function discountPresets(): array
    {
        $limit = auth()->user()->maxDiscountPercent();

        return array_values(array_filter(
            self::DISCOUNT_PRESETS,
            fn (float $percent) => $limit === null || $percent <= $limit,
        ));
    }

    public function lookup(): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $term = trim($this->code);
        $this->success = null;
        $this->error = null;

        if ($term === '') {
            $this->error = 'Skanoni ose shkruani kodin e biletës ose targën.';

            return;
        }

        // Exact ticket code match first, regardless of status, so a closed ticket gets its own
        // message instead of "not found".
        $session = ParkingSession::query()->where('ticket_code', strtoupper($term))->first();

        if ($session !== null) {
            if ($session->status !== ParkingSession::STATUS_ACTIVE) {
                $statusLabel = ParkingSession::statusLabel($session->status);
                $this->error = "Bileta {$session->ticket_code} është mbyllur tashmë ({$statusLabel}).";
                $this->code = '';

                return;
            }

            $this->selectTicket($session);

            return;
        }

        // No exact code — try the plate among active tickets.
        $matches = $this->results;

        if ($matches->count() === 1) {
            $this->selectTicket($matches->first());

            return;
        }

        if ($matches->count() > 1) {
            // Ambiguous: leave the typed text as is and let the operator tap the right one below.
            return;
        }

        $this->error = "Nuk ka biletë aktive me kodin ose targën \"{$term}\".";
        $this->code = '';
    }

    /** Picks a ticket straight from the search results list. */
    public function selectResult(int $id): void
    {
        Gate::authorize(Permissions::CHECKOUT);

        $session = ParkingSession::query()->active()->with('vehicleType')->find($id);

        if ($session === null) {
            $this->error = 'Bileta nuk është më aktive.';

            return;
        }

        $this->selectTicket($session);
    }

    private function selectTicket(ParkingSession $session): void
    {
        $this->error = null;
        $this->sessionId = $session->id;
        $this->code = '';
        $this->reset('finalPrice', 'amountReceived', 'reason', 'customDiscountPercent');
    }

    public function confirm(CheckoutService $checkout, TicketPrinter $printer): void
    {
        $this->complete($checkout, $printer);
    }

    /**
     * One tap for the common case: the customer handed over the exact price, no change to count.
     * Fills the received amount from the chargeable price (calculated, or the operator's typed
     * price when adjusted) and completes the checkout in the same click.
     */
    public function quickPay(CheckoutService $checkout, TicketPrinter $printer): void
    {
        if ($this->chargeable !== null && $this->chargeable > 0) {
            $this->amountReceived = $this->formatNumber($this->chargeable);
        }

        $this->complete($checkout, $printer);
    }

    /**
     * Fills the price field with a percentage off the calculated total and a default reason.
     * The role's discount limit, and whether a reason is required, are still enforced at confirm time.
     */
    public function applyDiscount(float $percent): void
    {
        if ($this->result === null) {
            return;
        }

        $calculated = $this->result->total();
        $this->finalPrice = $this->formatNumber(round($calculated * (1 - $percent / 100), 2));

        if (trim($this->reason) === '') {
            $this->reason = sprintf('Zbritje %s%%', rtrim(rtrim(number_format($percent, 2), '0'), '.'));
        }
    }

    /** Same as applyDiscount, but reads the percent the operator typed next to the "Apliko" button. */
    public function applyCustomDiscount(): void
    {
        $percent = $this->parseNumber($this->customDiscountPercent);

        if ($percent === null || $percent < 0) {
            $this->error = 'Shkruani një përqindje të vlefshme.';

            return;
        }

        $this->applyDiscount($percent);
    }

    /** Clears any typed price adjustment and reason, back to charging the calculated price. */
    public function clearAdjustment(): void
    {
        $this->reset('finalPrice', 'reason', 'customDiscountPercent');
    }

    /** Fills the reason field from a one-tap suggestion. */
    public function fillReason(string $text): void
    {
        $this->reason = $text;
    }

    /** Fills the received amount with the exact chargeable price, without submitting. */
    public function useExactAmount(): void
    {
        if ($this->chargeable !== null) {
            $this->amountReceived = $this->formatNumber($this->chargeable);
        }
    }

    public function cancel(): void
    {
        $this->reset('sessionId', 'finalPrice', 'amountReceived', 'reason', 'customDiscountPercent', 'error');
    }

    public function render()
    {
        return view('livewire.operator.checkout-screen', [
            'canAdjust' => auth()->user()->can(Permissions::ADJUST_PRICE),
            'discountLimit' => auth()->user()->maxDiscountPercent(),
            'reasonSuggestions' => self::REASON_SUGGESTIONS,
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

    /** Plain "123.45" text, safe to feed back into a field parseNumber reads (no thousand separators). */
    private function formatNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function complete(CheckoutService $checkout, TicketPrinter $printer): void
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
        $this->reset('sessionId', 'finalPrice', 'amountReceived', 'reason', 'customDiscountPercent');

        $this->dispatch('print-ticket', url: $printer->printReceipt($closed)->url);
    }
}
