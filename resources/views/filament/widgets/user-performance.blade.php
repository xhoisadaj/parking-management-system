<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Operators</x-slot>
        <x-slot name="description">{{ $label }}. Adjustment value is the net change from the calculated price.</x-slot>

        @if (empty($rows))
            <p class="text-sm text-gray-500">No activity in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10" data-testid="user-performance">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">Operator</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Issued</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Checkouts</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Revenue</th>
                            <th scope="col" class="px-2 py-2 text-right font-medium">Adjustments</th>
                            <th scope="col" class="py-2 pl-2 text-right font-medium">Adjustment value</th>
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
