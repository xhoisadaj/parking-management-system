<div class="space-y-4">
    @if ($error)
        <div role="alert" class="rounded-2xl bg-red-50 p-4 text-base font-medium text-red-800">{{ $error }}</div>
    @endif

    @if ($success)
        <div role="status" class="rounded-2xl bg-emerald-50 p-4 text-base font-semibold text-emerald-800">{{ $success }}</div>
    @endif

    @if ($this->selected)
        @php($session = $this->selected)
        <div class="space-y-4 rounded-2xl bg-white p-5 shadow" data-testid="edit-card">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="text-sm text-slate-500">{{ $session->vehicleType->name }}@if ($session->plate) · {{ $session->plate }}@endif</p>
                    <p class="font-mono text-2xl font-bold tracking-wider">{{ $session->ticket_code }}</p>
                </div>
                <button type="button" wire:click="cancel" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100">Mbyll</button>
            </div>

            <div>
                <label for="vehicle-type" class="block text-sm font-semibold text-slate-700">Lloji i mjetit</label>
                <select id="vehicle-type" wire:model="vehicleTypeId"
                    class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
                    @foreach ($vehicleTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="plate" class="block text-sm font-semibold text-slate-700">Targa</label>
                <input id="plate" type="text" wire:model="plate" autocomplete="off" autocapitalize="characters"
                    class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 font-mono uppercase tracking-widest focus:border-slate-900 focus:ring-slate-900">
            </div>

            <div>
                <label for="entered-at" class="block text-sm font-semibold text-slate-700">Ora e hyrjes</label>
                <input id="entered-at" type="datetime-local" wire:model="enteredAt"
                    class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
            </div>

            @if ($canAdjustPrice)
                <div class="space-y-3 rounded-xl border border-slate-200 p-4">
                    <div>
                        <label for="price-override" class="block text-sm font-semibold text-slate-700">Çmimi i personalizuar (opsionale)</label>
                        <p class="text-sm text-slate-500">Lëreni bosh për çmimin e llogaritur si zakonisht.</p>
                        <input id="price-override" type="text" inputmode="decimal" wire:model="priceOverride" data-numeric-input autocomplete="off"
                            class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-xl focus:border-slate-900 focus:ring-slate-900">
                    </div>
                    <div>
                        <label for="price-override-reason" class="block text-sm font-semibold text-slate-700">Arsyeja</label>
                        <input id="price-override-reason" type="text" wire:model="priceOverrideReason" maxlength="255" autocomplete="off"
                            class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
                    </div>
                </div>
            @endif

            <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="min-h-[56px] w-full rounded-xl bg-slate-900 text-base font-semibold text-white hover:bg-slate-800">
                Ruaj ndryshimet
            </button>
        </div>
    @else
        <div class="rounded-2xl bg-white p-5 shadow">
            <h2 class="text-sm font-semibold text-slate-700">Periudha</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach (['today' => 'Sot', 'shift' => 'Këtë turn', 'week' => '7 ditët e fundit'] as $value => $label)
                    <button type="button" wire:click="$set('period', '{{ $value }}')"
                        class="min-h-[44px] rounded-lg border px-4 text-sm font-semibold {{ $period === $value ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-100' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Biletat e mia</h2>
            <ul class="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white shadow" data-testid="my-tickets-list">
                @forelse ($this->tickets as $ticket)
                    <li data-copy-code="{{ $ticket->ticket_code }}" tabindex="0" role="button"
                        title="Kliko për të kopjuar kodin e biletës"
                        class="flex min-h-[64px] cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 transition hover:bg-slate-50 active:bg-slate-100">
                        <div class="min-w-0">
                            <span class="flex items-center gap-1.5">
                                <span class="block truncate font-mono text-base font-semibold" data-copy-label>{{ $ticket->ticket_code }}</span>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true">
                                    <path d="M7 3.5A1.5 1.5 0 0 1 8.5 2h3.879a1.5 1.5 0 0 1 1.06.44l3.122 3.12A1.5 1.5 0 0 1 17 6.622V12.5a1.5 1.5 0 0 1-1.5 1.5h-1v-3.379a3 3 0 0 0-.879-2.121L10.5 5.379A3 3 0 0 0 8.379 4.5H7v-1Z" />
                                    <path d="M4.5 6A1.5 1.5 0 0 0 3 7.5v9A1.5 1.5 0 0 0 4.5 18h7a1.5 1.5 0 0 0 1.5-1.5v-6.879a1.5 1.5 0 0 0-.44-1.06L9.44 5.439A1.5 1.5 0 0 0 8.378 5H4.5Z" />
                                </svg>
                            </span>
                            <span class="block text-sm text-slate-500">
                                {{ $ticket->vehicleType->name }}@if ($ticket->plate) · {{ $ticket->plate }}@endif
                                · {{ \App\Support\Format::dateTime($ticket->entered_at) }}
                            </span>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-xs font-semibold
                                {{ match($ticket->status) {
                                    \App\Models\ParkingSession::STATUS_ACTIVE => 'bg-emerald-50 text-emerald-700',
                                    \App\Models\ParkingSession::STATUS_PAID => 'bg-slate-100 text-slate-600',
                                    \App\Models\ParkingSession::STATUS_VOID => 'bg-red-50 text-red-700',
                                    default => 'bg-amber-50 text-amber-700',
                                } }}">
                                {{ \App\Models\ParkingSession::statusLabel($ticket->status) }}
                            </span>
                            @if ($ticket->status === \App\Models\ParkingSession::STATUS_ACTIVE)
                                <button type="button" wire:click="select({{ $ticket->id }})"
                                    class="min-h-[40px] rounded-lg border border-slate-300 px-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                    Ndrysho
                                </button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="p-5 text-slate-600">Asnjë biletë në këtë periudhë.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
