<x-filament-panels::page class="fi-dashboard-page">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
        <div class="min-w-0 sm:flex-1">
            {{ $this->filtersForm }}
        </div>
        <div class="flex flex-wrap gap-2">
            <x-filament::button tag="a" :href="$this->exportUrl('xlsx')" icon="heroicon-m-arrow-down-tray">
                Eksporto Excel
            </x-filament::button>
            <x-filament::button tag="a" color="gray" :href="$this->exportUrl('csv')" icon="heroicon-m-table-cells">
                Eksporto CSV
            </x-filament::button>
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
