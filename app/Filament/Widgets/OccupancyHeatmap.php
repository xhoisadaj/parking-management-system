<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use Filament\Widgets\Widget;

class OccupancyHeatmap extends Widget
{
    use ReadsPeriod;

    protected static string $view = 'filament.widgets.occupancy-heatmap';

    // Light enough to render with the page; avoids a lazy-load round trip.
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $period = $this->period();
        $heatmap = app(StatisticsService::class)->occupancyHeatmap($period->from, $period->to);

        return [
            'cells' => $heatmap['cells'],
            'max' => $heatmap['max'],
            'days' => ['Hën', 'Mar', 'Mër', 'Enj', 'Pre', 'Sht', 'Die'],
            'label' => $period->label(),
        ];
    }
}
