<div class="space-y-4">
    <div class="rounded-2xl bg-white p-4 shadow" data-testid="assigned-shift">
        @if ($workShift)
            <p class="text-sm text-slate-500">Turni juaj</p>
            <p class="text-lg font-semibold">{{ $workShift->name }} · {{ $workShift->hoursLabel() }}</p>
            @if ($enforceHours && $current && ! $insideHours)
                <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Jeni jashtë orarit të caktuar. Veprimet regjistrohen gjithsesi te turni juaj.</p>
            @endif
        @else
            <p class="text-sm text-slate-600">Ende nuk ju është caktuar asnjë turn. Kërkoni te menaxheri.</p>
        @endif
    </div>

    @if (session('shift_required'))
        <div role="alert" class="rounded-2xl bg-amber-50 p-4 text-base font-medium text-amber-800">
            Duhet të filloni turnin përpara se të vazhdoni.
        </div>
    @endif

    @if ($error)
        <div role="alert" class="rounded-2xl bg-red-50 p-4 text-base font-medium text-red-800">{{ $error }}</div>
    @endif

    @if ($success)
        <div role="status" class="rounded-2xl bg-emerald-50 p-4 text-base font-semibold text-emerald-800">{{ $success }}</div>
    @endif

    @if ($current && $summary)
        <div class="space-y-4 rounded-2xl bg-white p-5 shadow" data-testid="shift-card">
            <div>
                <p class="text-sm text-slate-500">Turni u hap</p>
                <p class="text-lg font-semibold">{{ \App\Support\Format::dateTime($current->opened_at) }}</p>
            </div>

            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Paraja fillestare në arkë</dt><dd class="text-2xl font-bold" data-testid="shift-opening-cash">{{ $current->opening_cash === null ? '—' : \App\Support\Format::money($current->opening_cash) }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Biletat e lëshuara</dt><dd class="text-2xl font-bold" data-testid="shift-issued">{{ $summary['tickets_issued'] }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Arkëtime</dt><dd class="text-2xl font-bold" data-testid="shift-checkouts">{{ $summary['checkouts'] }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm text-slate-500">Para të arkëtuara</dt><dd class="text-2xl font-bold" data-testid="shift-cash">{{ \App\Support\Format::money($summary['cash_collected']) }}</dd></div>
            </dl>

            @if ($confirmingClose)
                <div class="space-y-3 rounded-xl border-2 border-slate-900 p-4">
                    <p class="font-semibold">Ta mbyll këtë turn tani? Totalet nuk do të mund të ndryshohen më pas.</p>
                    @if ($requireCash)
                        <div>
                            <label for="closing-cash" class="block text-sm font-semibold text-slate-700">Sa para ka në arkë tani?</label>
                            <p class="text-sm text-slate-500">Numëroni arkën përpara se të mbyllni turnin. Shkruani 0 nëse arka është bosh.</p>
                            <input id="closing-cash" type="text" inputmode="decimal" wire:model="closingCash" data-numeric-input data-testid="closing-cash" autocomplete="off"
                                class="mt-2 block min-h-[56px] w-full rounded-xl border border-slate-300 text-xl focus:border-slate-900 focus:ring-slate-900">
                        </div>
                    @endif
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="button" wire:click="closeShift" wire:loading.attr="disabled" class="min-h-[52px] flex-1 rounded-xl bg-slate-900 text-base font-semibold text-white">Po, mbyll turnin</button>
                        <button type="button" wire:click="$set('confirmingClose', false)" class="min-h-[52px] flex-1 rounded-xl border border-slate-300 text-base font-semibold">Vazhdo punën</button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="$set('confirmingClose', true)" class="min-h-[56px] w-full rounded-xl bg-slate-900 text-base font-semibold text-white hover:bg-slate-800">
                    Mbyll turnin
                </button>
            @endif
        </div>
    @else
        <div class="space-y-3 rounded-2xl bg-white p-5 shadow" data-testid="no-shift-card">
            @if ($enforceHours && $workShift && ! $insideHours)
                <p class="text-slate-600" data-testid="outside-hours">
                    Nuk mund ta filloni turnin tani — turni juaj i caktuar është <strong>{{ $workShift->hoursLabel() }}</strong>.
                </p>
            @else
                <p class="text-slate-600">Nuk keni turn aktiv. Filloni turnin për të hyrë te Hyrja, Arkëtimi dhe Biletë e humbur.</p>
                @if ($requireCash)
                    <div>
                        <label for="opening-cash" class="block text-sm font-semibold text-slate-700">Sa para ka në arkë tani?</label>
                        <p class="text-sm text-slate-500">Numëroni arkën përpara se të filloni. Shkruani 0 nëse arka është bosh.</p>
                        <input id="opening-cash" type="text" inputmode="decimal" wire:model="openingCash" data-numeric-input data-testid="opening-cash" autocomplete="off"
                            class="mt-2 block min-h-[56px] w-full rounded-xl border border-slate-300 text-xl focus:border-slate-900 focus:ring-slate-900">
                    </div>
                @endif
                <button type="button" wire:click="startShift" wire:loading.attr="disabled" data-testid="start-shift"
                    class="min-h-[56px] w-full rounded-xl bg-slate-900 text-base font-semibold text-white hover:bg-slate-800">
                    Fillo turnin
                </button>
            @endif
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <div>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">Turnet e fundit</h2>
            <ul class="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white shadow">
                @foreach ($recent as $shift)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                        <span>{{ \App\Support\Format::dateTime($shift->closed_at) }}</span>
                        <span class="text-slate-600">Fillim {{ $shift->opening_cash === null ? '—' : \App\Support\Format::money($shift->opening_cash) }} · {{ $shift->tickets_issued }} lëshuar · {{ $shift->checkouts }} dalje · {{ \App\Support\Format::money($shift->cash_collected) }} · Mbyllje {{ $shift->closing_cash_declared === null ? '—' : \App\Support\Format::money($shift->closing_cash_declared) }}</span>
                        <span class="{{ $shift->reconciled_at ? 'text-emerald-700' : 'text-amber-700' }} font-medium">{{ $shift->reconciled_at ? 'Verifikuar' : 'Pret menaxherin' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
