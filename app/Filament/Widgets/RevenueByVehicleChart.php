<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use Filament\Widgets\ChartWidget;

class RevenueByVehicleChart extends ChartWidget
{
    use ReadsPeriod;

    protected static ?int $sort = 4;

    protected static ?string $heading = 'Revenue by vehicle type';

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $period = $this->period();
        $rows = app(StatisticsService::class)->revenueByVehicleType($period->from, $period->to);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => array_map(fn (array $row) => $row['revenue'], $rows),
                    'backgroundColor' => ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
                ],
            ],
            'labels' => array_map(fn (array $row) => $row['vehicle'].' ('.$row['tickets'].')', $rows),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
