<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use Filament\Widgets\Widget;

class OccupancyHeatmap extends Widget
{
    use ReadsPeriod;

    protected static string $view = 'filament.widgets.occupancy-heatmap';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $period = $this->period();
        $heatmap = app(StatisticsService::class)->occupancyHeatmap($period->from, $period->to);

        return [
            'cells' => $heatmap['cells'],
            'max' => $heatmap['max'],
            'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'label' => $period->label(),
        ];
    }
}
