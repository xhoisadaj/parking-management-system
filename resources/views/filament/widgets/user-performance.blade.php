<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Operatorët</x-slot>
        <x-slot name="description">{{ $label }}. Vlera e ndryshimeve është ndryshimi neto nga çmimi i llogaritur.</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">Asnjë aktivitet në këtë periudhë.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10" data-testid="user-performance">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">Operatori</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Të lëshuara</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Arkëtime</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Të ardhurat</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Ndryshime</th>
                            <th scope="col" class="py-2 pl-2 text-right font-medium">Vlera e ndryshimeve</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="whitespace-nowrap py-2 pr-4 font-medium">{{ $row['user'] }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $row['issued'] }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $row['checkouts'] }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ \App\Support\Format::money($row['revenue'], '') }}</td>
                                <td class="px-2 py-2 text-right tabular-nums">{{ $row['adjustments'] }}</td>
                                <td class="whitespace-nowrap py-2 pl-2 text-right tabular-nums {{ $row['adjustment_value'] < 0 ? 'text-red-600' : '' }}">
                                    {{ $row['adjustment_value'] > 0 ? '+' : '' }}{{ \App\Support\Format::money($row['adjustment_value'], '') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
