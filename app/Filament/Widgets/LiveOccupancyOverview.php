<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LiveOccupancyOverview extends StatsOverviewWidget
{
    use ReadsPeriod;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $live = app(StatisticsService::class)->liveOccupancy();

        $cards = [
            Stat::make('Vende të lira', \App\Support\Format::amount($live['free_spots'], 1, true))
                ->description('nga '.\App\Support\Format::amount($live['total_spots'], 1, true).' gjithsej')
                ->color($live['free_spots'] <= 0 ? 'danger' : 'success'),
        ];

        foreach ($live['types'] as $type) {
            $cap = $type['dedicated'] !== null ? ' · kufiri '.$type['dedicated'] : '';

            $cards[] = Stat::make($type['name'], $type['vehicles'].' të parkuara')
                ->description($type['free'].' të lira'.$cap)
                ->color($type['free'] <= 0 ? 'danger' : 'gray');
        }

        return $cards;
    }
}
