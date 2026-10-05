<div class="space-y-4">
    @if ($success)
        <div role="status" class="rounded-2xl bg-emerald-50 p-4 text-base font-semibold text-emerald-800">{{ $success }}</div>
    @endif

    @if ($error)
        <div role="alert" class="rounded-2xl bg-red-50 p-4 text-base font-medium text-red-800">{{ $error }}</div>
    @endif

    @unless ($this->session)
        <form wire:submit="lookup" class="rounded-2xl bg-white p-5 shadow">
            <label for="ticket-code" class="block text-sm font-semibold text-slate-700">Ticket code</label>
            <p class="text-sm text-slate-500">Scan the barcode or type the code, then press Enter.</p>
            <div class="mt-3 flex gap-2">
                <input id="ticket-code" type="text" wire:model="code" data-autofocus autofocus autocomplete="off" autocapitalize="characters" spellcheck="false"
                    class="block min-h-[60px] w-full rounded-xl border border-slate-300 font-mono text-2xl uppercase tracking-widest focus:border-slate-900 focus:ring-slate-900">
                <button type="submit" class="min-h-[60px] shrink-0 rounded-xl bg-slate-900 px-6 text-base font-semibold text-white hover:bg-slate-800">
                    Find
                </button>
            </div>
        </form>
    @else
        @php($session = $this->session)
        @php($result = $this->result)
        <div class="space-y-4 rounded-2xl bg-white p-5 shadow" data-testid="checkout-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-sm text-slate-500">{{ $session->vehicleType->name }}@if ($session->plate) · {{ $session->plate }}@endif</p>
                    <p class="font-mono text-2xl font-bold tracking-wider">{{ $session->ticket_code }}</p>
                </div>
                <button type="button" wire:click="cancel" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Entered</dt><dd class="text-base font-semibold">{{ \App\Support\Format::dateTime($session->entered_at) }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Exit</dt><dd class="text-base font-semibold">{{ \App\Support\Format::dateTime(now()) }}</dd></div>
                <div class="col-span-2 rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Duration</dt><dd class="text-base font-semibold">{{ \App\Support\Format::duration($result->durationMinutes) }}</dd></div>
            </dl>

            <div>
                <h2 class="text-sm font-semibold text-slate-700">Price breakdown</h2>
                @if ($result->graceApplied)
                    <p class="mt-2 rounded-lg bg-emerald-50 p-3 text-emerald-800">Within the grace period. Free.</p>
                @else
                    <ul class="mt-2 divide-y divide-slate-100 text-sm">
                        @foreach ($result->toArray()['days'] as $day)
                            @foreach ($day['lines'] as $line)
                                <li class="flex justify-between gap-3 py-2">
                                    <span>{{ \Carbon\Carbon::parse($day['date'])->format('d M') }} · {{ $line['label'] }} · {{ $line['units'] }} × {{ \App\Support\Format::money($line['unit_price'], '') }}</span>
                                    <span class="font-medium">{{ \App\Support\Format::money($line['amount'], '') }}</span>
                                </li>
                            @endforeach
                            @if ($day['daily_cap_applied'])
                                <li class="flex justify-between gap-3 py-2 text-emerald-700">
                                    <span>Daily maximum applied</span>
                                    <span>−{{ \App\Support\Format::money($day['gross'] - $day['charged'], '') }}</span>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                @endif
                <div class="mt-3 flex items-center justify-between border-t-2 border-slate-900 pt-3">
                    <span class="text-base font-semibold">Calculated</span>
                    <span class="text-2xl font-bold" data-testid="calculated-price">{{ \App\Support\Format::money($result->total()) }}</span>
                </div>
            </div>

            <form wire:submit="confirm" class="space-y-4">
                @if ($canAdjust)
                    <div>
                        <label for="final-price" class="block text-sm font-semibold text-slate-700">Final price <span class="font-normal text-slate-500">(leave empty to charge the calculated price)</span></label>
                        <input id="final-price" type="text" inputmode="decimal" wire:model.live.debounce.300ms="finalPrice" autocomplete="off"
                            class="mt-1 block min-h-[56px] w-full rounded-xl border border-slate-300 text-xl focus:border-slate-900 focus:ring-slate-900">
                        <p class="mt-1 text-sm text-slate-500">
                            @if ($discountLimit === null) No discount limit for your role.
                            @elseif ($discountLimit <= 0) Your role cannot give discounts. You may still raise the price.
                            @else Your discount limit is {{ rtrim(rtrim(number_format($discountLimit, 2), '0'), '.') }}%.
                            @endif
                        </p>
                    </div>
                    <div>
                        <label for="reason" class="block text-sm font-semibold text-slate-700">
                            Reason
                            @if ($this->reasonRequired)
                                <span class="font-normal text-red-700">(required for this change)</span>
                            @else
                                <span class="font-normal text-slate-500">(optional)</span>
                            @endif
                        </label>
                        <input id="reason" type="text" wire:model="reason" maxlength="255" autocomplete="off"
                            class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
                    </div>
                @endif

                <div class="space-y-2 rounded-xl border border-slate-200 p-4">
                    <label for="amount-received" class="block text-sm font-semibold text-slate-700">
                        Amount received from the customer <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="amount-received" type="text" inputmode="decimal" wire:model.live.debounce.300ms="amountReceived" autocomplete="off" data-testid="amount-received"
                        class="block min-h-[56px] w-full rounded-xl border border-slate-300 text-xl focus:border-slate-900 focus:ring-slate-900">

                    @if ($this->cash)
                        @if ($this->cash['short'])
                            <p class="rounded-lg bg-red-50 p-3 text-base font-semibold text-red-800" data-testid="cash-short">
                                Short by {{ \App\Support\Format::money($this->cash['short_by'], '') }}
                            </p>
                        @else
                            <div class="flex items-center justify-between rounded-lg bg-emerald-50 p-3" data-testid="cash-change">
                                <span class="text-base font-semibold text-emerald-800">Give back</span>
                                <span class="text-2xl font-bold text-emerald-800">{{ \App\Support\Format::money($this->cash['change']) }}</span>
                            </div>
                        @endif
                    @endif
                </div>

                <button type="submit" wire:loading.attr="disabled"
                    class="min-h-[64px] w-full rounded-2xl bg-emerald-600 text-lg font-bold text-white shadow hover:bg-emerald-700 active:scale-[0.99]">
                    Confirm and print receipt
                </button>
            </form>
        </div>
    @endunless
</div>
