<div class="space-y-4">
    @if ($success)
        <div role="status" class="rounded-2xl bg-emerald-50 p-4 text-base font-semibold text-emerald-800">{{ $success }}</div>
    @endif

    @if ($error)
        <div role="alert" class="rounded-2xl bg-red-50 p-4 text-base font-medium text-red-800">{{ $error }}</div>
    @endif

    @if ($this->selected)
        @php($selected = $this->selected)
        <div class="space-y-4 rounded-2xl bg-white p-5 shadow" data-testid="lost-card">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="text-sm text-slate-500">{{ $selected->vehicleType->name }}@if ($selected->plate) · {{ $selected->plate }}@endif</p>
                    <p class="font-mono text-2xl font-bold tracking-wider">{{ $selected->ticket_code }}</p>
                    <p class="text-sm text-slate-500">Entered {{ \App\Support\Format::dateTime($selected->entered_at) }}</p>
                </div>
                <button type="button" wire:click="clearSelection" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100">Back</button>
            </div>

            <div class="space-y-1">
                <label for="lost-cash" class="block text-sm font-semibold text-slate-700">Amount received <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="lost-cash" type="text" inputmode="decimal" wire:model="amountReceived" autocomplete="off"
                    class="block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
            </div>

                        <button type="button" wire:click="markLost" wire:loading.attr="disabled"
                class="min-h-[60px] w-full rounded-2xl bg-amber-500 text-lg font-bold text-white shadow hover:bg-amber-600">
                Charge lost-ticket fee {{ \App\Support\Format::money(\App\Models\Setting::current()->lost_ticket_fee) }}
            </button>

            @if ($canVoid)
                <div class="space-y-2 border-t border-slate-200 pt-4">
                    <label for="void-reason" class="block text-sm font-semibold text-slate-700">Void this ticket (issued by mistake)</label>
                    <input id="void-reason" type="text" wire:model="voidReason" maxlength="255" placeholder="Reason"
                        class="block min-h-[52px] w-full rounded-xl border border-slate-300 text-base focus:border-slate-900 focus:ring-slate-900">
                    <button type="button" wire:click="voidTicket" wire:loading.attr="disabled"
                        class="min-h-[52px] w-full rounded-xl border-2 border-red-600 text-base font-semibold text-red-700 hover:bg-red-50">
                        Void ticket and free the spot
                    </button>
                </div>
            @endif
        </div>
    @else
        <div class="rounded-2xl bg-white p-5 shadow">
            <label for="search" class="block text-sm font-semibold text-slate-700">Search by plate or ticket code</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off" autocapitalize="characters"
                class="mt-1 block min-h-[56px] w-full rounded-xl border border-slate-300 text-lg uppercase focus:border-slate-900 focus:ring-slate-900"
                placeholder="Plate or code">
        </div>

        <div>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Cars in the lot</h2>
            <ul class="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white shadow" data-testid="active-list">
                @forelse ($this->tickets as $ticket)
                    <li>
                        <button type="button" wire:click="select({{ $ticket->id }})"
                            class="flex min-h-[64px] w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block truncate text-base font-semibold">{{ $ticket->plate ?: 'No plate' }}</span>
                                <span class="block text-sm text-slate-500">{{ $ticket->vehicleType->name }} · {{ $ticket->ticket_code }}</span>
                            </span>
                            <span class="shrink-0 text-sm text-slate-500">{{ \App\Support\Format::time($ticket->entered_at) }}</span>
                        </button>
                    </li>
                @empty
                    <li class="p-5 text-slate-600">No cars match. Check the spelling or try the ticket code.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
