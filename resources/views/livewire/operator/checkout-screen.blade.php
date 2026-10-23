<div class="space-y-4">
    @if ($success)
        <div role="status" class="rounded-2xl bg-emerald-50 p-4 text-base font-semibold text-emerald-800">{{ $success }}</div>
    @endif

    @if ($error)
        <div role="alert" class="rounded-2xl bg-red-50 p-4 text-base font-medium text-red-800">{{ $error }}</div>
    @endif

    @unless ($this->session)
        <form wire:submit="lookup" class="rounded-2xl bg-white p-5 shadow">
            <label for="ticket-code" class="block text-sm font-semibold text-slate-700">Kodi i biletës ose targa</label>
            <p class="text-sm text-slate-500">Skanoni kodin QR, ose shkruani kodin ose targën dhe shtypni Enter.</p>
            <div class="mt-3 flex gap-2">
                <input id="ticket-code" type="text" wire:model.live.debounce.300ms="code" data-autofocus autofocus autocomplete="off" autocapitalize="characters" spellcheck="false"
                    class="block min-h-[60px] w-full rounded-xl border border-slate-300 font-mono text-2xl uppercase tracking-widest focus:border-slate-900 focus:ring-slate-900">
                <button type="submit" class="min-h-[60px] shrink-0 rounded-xl bg-slate-900 px-6 text-base font-semibold text-white hover:bg-slate-800">
                    Gjej
                </button>
            </div>
        </form>

        @if ($this->results->isNotEmpty())
            <div>
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Zgjidhni biletën</h2>
                <ul class="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white shadow" data-testid="search-results">
                    @foreach ($this->results as $ticket)
                        <li>
                            <button type="button" wire:click="selectResult({{ $ticket->id }})"
                                class="flex min-h-[64px] w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50">
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-semibold">{{ $ticket->plate ?: 'Pa targë' }}</span>
                                    <span class="block text-sm text-slate-500">{{ $ticket->vehicleType->name }} · {{ $ticket->ticket_code }}</span>
                                </span>
                                <span class="shrink-0 text-sm text-slate-500">{{ \App\Support\Format::time($ticket->entered_at) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        @php($session = $this->session)
        @php($result = $this->result)
        <div class="space-y-4 rounded-2xl bg-white p-5 shadow" data-testid="checkout-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-sm text-slate-500">{{ $session->vehicleType->name }}@if ($session->plate) · {{ $session->plate }}@endif</p>
                    <p class="font-mono text-2xl font-bold tracking-wider">{{ $session->ticket_code }}</p>
                </div>
                <button type="button" wire:click="cancel" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100">Mbyll</button>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Hyrja</dt><dd class="text-base font-semibold">{{ \App\Support\Format::dateTime($session->entered_at) }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Dalja</dt><dd class="text-base font-semibold">{{ \App\Support\Format::dateTime(now()) }}</dd></div>
                <div class="col-span-2 rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Kohëzgjatja</dt><dd class="text-base font-semibold">{{ \App\Support\Format::duration($result->durationMinutes) }}</dd></div>
            </dl>

            <div>
                <h2 class="text-sm font-semibold text-slate-700">Zbërthimi i çmimit</h2>
                @if ($result->graceApplied)
                    <p class="mt-2 rounded-lg bg-emerald-50 p-3 text-emerald-800">Brenda periudhës falas. Falas.</p>
                @else
                    <ul class="mt-2 divide-y divide-slate-100 text-sm">
                        @foreach ($result->toArray()['days'] as $day)
                            @if ($day['long_stay_applied'])
                                <li class="flex justify-between gap-3 py-2">
                                    <span>{{ \Carbon\Carbon::parse($day['date'])->locale('sq')->translatedFormat('d M') }} · Tarifa ditore (qëndrim i gjatë)</span>
                                    <span class="font-medium">{{ \App\Support\Format::money($day['charged'], '') }}</span>
                                </li>
                            @else
                                @foreach ($day['lines'] as $line)
                                    <li class="flex justify-between gap-3 py-2">
                                        <span>{{ \Carbon\Carbon::parse($day['date'])->locale('sq')->translatedFormat('d M') }} · {{ $line['label'] }} · {{ $line['units'] }} × {{ \App\Support\Format::money($line['unit_price'], '') }}</span>
                                        <span class="font-medium">{{ \App\Support\Format::money($line['amount'], '') }}</span>
                                    </li>
                                @endforeach
                                @if ($day['daily_cap_applied'])
                                    <li class="flex justify-between gap-3 py-2 text-emerald-700">
                                        <span>Maksimumi ditor u aplikua</span>
                                        <span>−{{ \App\Support\Format::money($day['gross'] - $day['charged'], '') }}</span>
                                    </li>
                                @endif
                            @endif
                        @endforeach
                    </ul>
                @endif
                <div class="mt-3 flex items-center justify-between border-t-2 border-slate-900 pt-3">
                    <span class="text-base font-semibold">Të llogaritur</span>
                    <span class="text-2xl font-bold" data-testid="calculated-price">{{ \App\Support\Format::money($result->total()) }}</span>
                </div>

                @if ($this->presetPrice !== null)
                    <div class="mt-3 rounded-xl bg-amber-50 p-3" data-testid="preset-price">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-amber-900">Çmimi i caktuar nga {{ $this->session->adjustedBy?->name ?? 'dikush tjetër' }}</span>
                            <span class="text-xl font-bold text-amber-900">{{ \App\Support\Format::money($this->presetPrice) }}</span>
                        </div>
                        <p class="mt-1 text-sm text-amber-800">Ky çmim do të arkëtohet, jo ai i llogaritur më sipër, përveç nëse e ndryshoni më poshtë.</p>
                    </div>
                @endif
            </div>

            <form wire:submit="confirm" class="space-y-4">
                @if ($canAdjust)
                    <div>
                        <label for="final-price" class="block text-sm font-semibold text-slate-700">Çmimi përfundimtar <span class="font-normal text-slate-500">(lëreni bosh për të paguar çmimin e llogaritur)</span></label>
                        <input id="final-price" type="text" inputmode="decimal" wire:model.live.debounce.300ms="finalPrice" autocomplete="off" data-numeric-input
                            class="mt-1 block min-h-[56px] w-full rounded-xl border text-xl focus:ring-slate-900 {{ ($this->finalPriceInvalid || $this->discountExceededMessage) ? 'border-red-400 focus:border-red-500' : 'border-slate-300 focus:border-slate-900' }}">
                        @if ($this->finalPriceInvalid)
                            <p class="mt-1 text-sm font-semibold text-red-700">Çmimi duhet të jetë numër.</p>
                        @elseif ($this->discountExceededMessage)
                            <p class="mt-1 text-sm font-semibold text-red-700" data-testid="discount-exceeded">{{ $this->discountExceededMessage }}</p>
                        @else
                            <p class="mt-1 text-sm text-slate-500">
                                @if ($discountLimit === null) Roli juaj nuk ka limit zbritjeje.
                                @elseif ($discountLimit <= 0) Roli juaj nuk mund të japë zbritje. Mund ta rritni çmimin.
                                @else Limiti juaj i zbritjes është {{ \App\Support\Format::amount($discountLimit, 2, true) }}%.
                                @endif
                            </p>
                        @endif

                        @if ($discountLimit === null || $discountLimit > 0)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @foreach ($this->discountPresets as $percent)
                                    <button type="button" wire:click="applyDiscount({{ $percent }})"
                                        class="min-h-[44px] rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                        −{{ \App\Support\Format::amount($percent, 2, true) }}%
                                    </button>
                                @endforeach
                                <div class="flex min-h-[44px] items-center gap-1 rounded-lg border border-slate-300 pl-3 pr-1">
                                    <input type="text" inputmode="decimal" wire:model="customDiscountPercent" data-numeric-input placeholder="%" autocomplete="off"
                                        class="w-14 border-0 bg-transparent p-0 text-sm focus:ring-0">
                                    <button type="button" wire:click="applyCustomDiscount" class="min-h-[36px] rounded-md bg-slate-100 px-2 text-sm font-semibold text-slate-700 hover:bg-slate-200">
                                        Apliko
                                    </button>
                                </div>
                                @if ($this->finalPrice !== '')
                                    <button type="button" wire:click="clearAdjustment" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-slate-500 hover:bg-slate-100">
                                        Anulo ndryshimin
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div>
                        <label for="reason" class="block text-sm font-semibold text-slate-700">
                            Arsyeja
                            @if ($this->reasonRequired)
                                <span class="font-normal text-red-700">(e detyrueshme për këtë ndryshim)</span>
                            @else
                                <span class="font-normal text-slate-500">(opsionale)</span>
                            @endif
                        </label>
                        <input id="reason" type="text" wire:model="reason" maxlength="255" autocomplete="off"
                            class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($reasonSuggestions as $suggestion)
                                <button type="button" wire:click="fillReason(@js($suggestion))"
                                    class="min-h-[36px] rounded-full border border-slate-300 px-3 text-xs font-medium text-slate-600 hover:bg-slate-100">
                                    {{ $suggestion }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="space-y-2 rounded-xl border border-slate-200 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <label for="amount-received" class="block text-sm font-semibold text-slate-700">
                            Shuma e marrë nga klienti
                        </label>
                        <button type="button" wire:click="useExactAmount" class="min-h-[36px] shrink-0 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                            Shuma e saktë
                        </button>
                    </div>
                    <input id="amount-received" type="text" inputmode="decimal" wire:model.live.debounce.300ms="amountReceived" autocomplete="off" data-testid="amount-received" data-numeric-input
                        class="block min-h-[56px] w-full rounded-xl border text-xl focus:ring-slate-900 {{ $this->amountReceivedInvalid ? 'border-red-400 focus:border-red-500' : 'border-slate-300 focus:border-slate-900' }}">

                    @if ($this->amountReceivedInvalid)
                        <p class="rounded-lg bg-red-50 p-3 text-base font-semibold text-red-800">Shuma duhet të jetë numër.</p>
                    @elseif ($this->cash)
                        @if ($this->cash['short'])
                            <p class="rounded-lg bg-red-50 p-3 text-base font-semibold text-red-800" data-testid="cash-short">
                                Mungojnë {{ \App\Support\Format::money($this->cash['short_by'], '') }}
                            </p>
                        @else
                            <div class="flex items-center justify-between rounded-lg bg-emerald-50 p-3" data-testid="cash-change">
                                <span class="text-base font-semibold text-emerald-800">Kthe kusurin</span>
                                <span class="text-2xl font-bold text-emerald-800">{{ \App\Support\Format::money($this->cash['change']) }}</span>
                            </div>
                        @endif
                    @endif
                </div>

                <button type="submit" wire:loading.attr="disabled" @disabled($this->blockedFromConfirming)
                    class="min-h-[64px] w-full rounded-2xl bg-emerald-600 text-lg font-bold text-white shadow hover:bg-emerald-700 active:scale-[0.99] disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500">
                    Konfirmo dhe printo kuponin
                </button>
                <button type="button" wire:click="quickPay" wire:loading.attr="disabled" @disabled($this->blockedFromConfirming)
                    class="min-h-[56px] w-full rounded-2xl border-2 border-emerald-600 text-base font-bold text-emerald-700 hover:bg-emerald-50 active:scale-[0.99] disabled:cursor-not-allowed disabled:border-slate-300 disabled:text-slate-400">
                    Shuma e saktë, konfirmo dhe printo
                </button>
            </form>
        </div>
    @endunless
</div>
