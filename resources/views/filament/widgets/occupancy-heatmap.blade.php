<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Peak times · average occupied spots</x-slot>
        <x-slot name="description">{{ $label }}. Each cell is the average over the matching weekday and hour. Darker means fuller.</x-slot>

        <div class="overflow-x-auto">
            <table class="min-w-[32rem] w-full border-separate border-spacing-1 text-center text-xs" data-testid="occupancy-heatmap">
                <thead>
                    <tr>
                        <th scope="col" class="sticky left-0 bg-white px-1 text-left font-medium text-gray-500 dark:bg-gray-900">Hour</th>
                        @foreach ($days as $day)
                            <th scope="col" class="px-1 font-medium text-gray-500">{{ $day }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @for ($hour = 0; $hour < 24; $hour++)
                        <tr>
                            <th scope="row" class="sticky left-0 bg-white pr-2 text-right font-normal text-gray-500 dark:bg-gray-900">{{ sprintf('%02d:00', $hour) }}</th>
                            @for ($weekday = 1; $weekday <= 7; $weekday++)
                                @php($value = $cells[$weekday][$hour] ?? null)
                                @php($alpha = ($value === null || $max <= 0) ? 0 : max(0.08, $value / $max))
                                <td class="h-7 min-w-[2.5rem] rounded"
                                    style="background-color: rgba(37, 99, 235, {{ $value === null ? 0.04 : $alpha }}); color: {{ $alpha > 0.55 ? '#fff' : '#111827' }};"
                                    title="{{ $days[$weekday - 1] }} {{ sprintf('%02d:00', $hour) }}: {{ $value === null ? 'no data' : $value.' spots' }}">
                                    {{ $value === null ? '' : rtrim(rtrim(number_format($value, 1), '0'), '.') }}
                                </td>
                            @endfor
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
