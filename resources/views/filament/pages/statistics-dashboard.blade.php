<x-filament-panels::page class="fi-dashboard-page">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0 flex-1">
            {{ $this->filtersForm }}
        </div>
        <div class="flex shrink-0 gap-2">
            <a href="{{ $this->exportUrl('xlsx') }}" class="inline-flex min-h-[44px] items-center rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white shadow hover:bg-primary-500">Export Excel</a>
            <a href="{{ $this->exportUrl('csv') }}" class="inline-flex min-h-[44px] items-center rounded-lg bg-white px-4 text-sm font-semibold text-gray-900 shadow ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-white dark:ring-gray-700">Export CSV</a>
        </div>
    </div>

    <x-filament-widgets::widgets
        :columns="$this->getColumns()"
        :data="[
            'filters' => $this->filters,
            ...$this->getWidgetData(),
        ]"
        :widgets="$this->getVisibleWidgets()"
    />
</x-filament-panels::page>
