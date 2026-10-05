<div wire:poll.10s class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-white p-4 shadow">
        <div>
            <p class="text-sm text-slate-500">Free spots</p>
            <p class="text-2xl font-bold" data-testid="pool-free">{{ rtrim(rtrim(number_format($poolFree, 1), '0'), '.') }} <span class="text-base font-normal text-slate-500">of {{ rtrim(rtrim(number_format($poolTotal, 1), '0'), '.') }}</span></p>
        </div>
        @if ($lotClosedToday)
            <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">Closed today</span>
        @elseif (! $lotOpen)
            <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">Outside opening hours</span>
        @else
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">Open</span>
        @endif
    </div>

    @if ($error)
        <div role="alert" class="rounded-2xl bg-red-50 p-4 text-base font-medium text-red-800">{{ $error }}</div>
    @endif

    @if ($issued)
        <div class="rounded-2xl border-2 border-emerald-500 bg-emerald-50 p-5" data-testid="issued-ticket">
            <p class="text-sm font-semibold text-emerald-800">Ticket issued · {{ $issued['vehicle'] }} · {{ $issued['entered'] }}</p>
            <p class="mt-2 break-all font-mono text-4xl font-bold tracking-wider">{{ $issued['code'] }}</p>
            <button type="button" wire:click="reprint" class="mt-4 min-h-[52px] rounded-xl bg-white px-5 text-base font-semibold text-emerald-900 shadow ring-1 ring-emerald-300 hover:bg-emerald-100">
                Print again
            </button>
        </div>
    @endif

    <div class="rounded-2xl bg-white p-4 shadow">
        <label for="plate" class="block text-sm font-medium text-slate-700">Plate (optional)</label>
        <input id="plate" type="text" wire:model="plate" maxlength="20" autocomplete="off" autocapitalize="characters"
            class="mt-1 block min-h-[52px] w-full rounded-xl border border-slate-300 text-lg uppercase focus:border-slate-900 focus:ring-slate-900"
            placeholder="AA-123-BB">
    </div>

    <div>
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Choose vehicle type</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($types as $row)
                @php($type = $row['model'])
                @php($full = $row['free'] <= 0)
                <button type="button"
                    wire:click="issue({{ $type->id }})"
                    wire:loading.attr="disabled"
                    @disabled($full)
                    class="flex min-h-[112px] flex-col items-start justify-between rounded-2xl p-5 text-left shadow transition
                        {{ $full ? 'cursor-not-allowed bg-slate-200 text-slate-500' : 'bg-slate-900 text-white hover:bg-slate-800 active:scale-[0.99]' }}">
                    <span class="text-xl font-bold">{{ $type->name }}</span>
                    <span class="text-sm {{ $full ? 'text-slate-500' : 'text-slate-300' }}">
                        {{ $full ? 'Full' : $row['free'].' free' }} · uses {{ rtrim(rtrim(number_format((float) $type->spots_used, 2), "0"), ".") }} {{ (float) $type->spots_used === 1.0 ? "spot" : "spots" }}
                    </span>
                </button>
            @empty
                <p class="rounded-2xl bg-white p-5 text-slate-600 shadow sm:col-span-2 lg:col-span-3">No vehicle types are active. Ask a manager to set them up.</p>
            @endforelse
        </div>
    </div>
</div>
