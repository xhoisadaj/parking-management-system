<div class="space-y-4">
    <div class="rounded-2xl bg-white p-4 shadow" data-testid="assigned-shift">
        @if ($workShift)
            <p class="text-sm text-slate-500">Your shift</p>
            <p class="text-lg font-semibold">{{ $workShift->name }} · {{ $workShift->hoursLabel() }}</p>
            @if (! $insideHours)
                <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">It is outside your scheduled hours now. Actions are still recorded on your shift.</p>
            @endif
        @else
            <p class="text-sm text-slate-600">No shift has been assigned to you yet. Ask a manager.</p>
        @endif
    </div>

    @if ($success)
        <div role="status" class="rounded-2xl bg-emerald-50 p-4 text-base font-semibold text-emerald-800">{{ $success }}</div>
    @endif

    @if ($current && $summary)
        <div class="space-y-4 rounded-2xl bg-white p-5 shadow" data-testid="shift-card">
            <div>
                <p class="text-sm text-slate-500">Shift opened</p>
                <p class="text-lg font-semibold">{{ \App\Support\Format::dateTime($current->opened_at) }}</p>
            </div>

            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Tickets issued</dt><dd class="text-2xl font-bold" data-testid="shift-issued">{{ $summary['tickets_issued'] }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Checkouts</dt><dd class="text-2xl font-bold" data-testid="shift-checkouts">{{ $summary['checkouts'] }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Cash collected</dt><dd class="text-2xl font-bold" data-testid="shift-cash">{{ \App\Support\Format::money($summary['cash_collected']) }}</dd></div>
            </dl>

            @if ($confirmingClose)
                <div class="space-y-3 rounded-xl border-2 border-slate-900 p-4">
                    <p class="font-semibold">Close this shift now? You will not be able to change its totals afterwards.</p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="button" wire:click="closeShift" wire:loading.attr="disabled" class="min-h-[52px] flex-1 rounded-xl bg-slate-900 text-base font-semibold text-white">Yes, close shift</button>
                        <button type="button" wire:click="$set('confirmingClose', false)" class="min-h-[52px] flex-1 rounded-xl border border-slate-300 text-base font-semibold">Keep working</button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="$set('confirmingClose', true)" class="min-h-[56px] w-full rounded-xl bg-slate-900 text-base font-semibold text-white hover:bg-slate-800">
                    Close shift
                </button>
            @endif
        </div>
    @else
        <p class="rounded-2xl bg-white p-5 shadow">Your shift opens automatically when you issue or close a ticket.</p>
    @endif

    @if ($recent->isNotEmpty())
        <div>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Recent shifts</h2>
            <ul class="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white shadow">
                @foreach ($recent as $shift)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                        <span>{{ \App\Support\Format::dateTime($shift->closed_at) }}</span>
                        <span class="text-slate-600">{{ $shift->tickets_issued }} issued · {{ $shift->checkouts }} out · {{ \App\Support\Format::money($shift->cash_collected) }}</span>
                        <span class="{{ $shift->reconciled_at ? 'text-emerald-700' : 'text-amber-700' }} font-medium">{{ $shift->reconciled_at ? 'Reconciled' : 'Awaiting manager' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
