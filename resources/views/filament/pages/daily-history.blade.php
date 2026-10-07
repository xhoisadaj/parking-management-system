<x-filament-panels::page>
    {{ $this->form }}

    <p class="text-sm text-gray-500">{{ $this->periodLabel }}@if (! empty($this->data['operator_id'])) · operatori i zgjedhur @else · të gjithë operatorët @endif</p>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="day-summary">
        @php($s = $this->summary)
        @foreach ([
            ['Biletat e lëshuara', $s['issued']],
            ['Ende në parking', $s['still_parked']],
            ['Të mbyllura', $s['closed']],
            ['Të anuluara', $s['voided']],
            ['Të paguara', $s['paid']],
            ['Të humbura', $s['lost']],
            ['Të ardhurat', \App\Support\Format::money($s['revenue'])],
        ] as [$label, $value])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <x-filament::section heading="Operatorët në këtë ditë" description="Të lëshuara sipas hyrjes, arkëtime dhe të ardhura sipas daljes.">
        @if (empty($this->operators))
            <p class="text-sm text-gray-500">Asnjë aktivitet nuk u regjistrua për periudhën e zgjedhur.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm" data-testid="day-operators">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">Operatori</th>
                            <th class="px-2 py-2 text-right font-medium">Të lëshuara</th>
                            <th class="px-2 py-2 text-right font-medium">Arkëtime</th>
                            <th class="px-2 py-2 text-right font-medium">Të ardhurat</th>
                            <th class="px-2 py-2 text-right font-medium">Ndryshime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($this->operators as $row)
                            <tr>
                                <td class="whitespace-nowrap py-2 pr-4 font-medium">{{ $row['user'] }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $row['issued'] }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $row['checkouts'] }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ \App\Support\Format::money($row['revenue'], '') }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $row['adjustments'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Biletat">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
