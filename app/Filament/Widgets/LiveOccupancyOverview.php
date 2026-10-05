<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LiveOccupancyOverview extends StatsOverviewWidget
{
    use ReadsPeriod;

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $live = app(StatisticsService::class)->liveOccupancy();

        $cards = [
            Stat::make('Free spots', rtrim(rtrim(number_format($live['free_spots'], 1), '0'), '.'))
                ->description('of '.rtrim(rtrim(number_format($live['total_spots'], 1), '0'), '.').' in total')
                ->color($live['free_spots'] <= 0 ? 'danger' : 'success'),
        ];

        foreach ($live['types'] as $type) {
            $cap = $type['dedicated'] !== null ? ' · cap '.$type['dedicated'] : '';

            $cards[] = Stat::make($type['name'], $type['vehicles'].' parked')
                ->description($type['free'].' free'.$cap)
                ->color($type['free'] <= 0 ? 'danger' : 'gray');
        }

        return $cards;
    }
}
