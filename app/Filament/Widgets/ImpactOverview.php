<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ImpactOverview extends StatsOverviewWidget
{
    use ReadsPeriod;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $period = $this->period();
        $figures = app(StatisticsService::class)->calculatedVsActual($period->from, $period->to);

        $impact = $figures['impact'];
        $impactText = ($impact > 0 ? '+' : '').Format::money($impact);

        return [
            Stat::make('Calculated', Format::money($figures['calculated']))
                ->description('Paid tickets at the tariff price'),
            Stat::make('Actual', Format::money($figures['actual']))
                ->description('What was really charged')
                ->color($impact < 0 ? 'warning' : 'success'),
            Stat::make('Impact of adjustments', $impactText)
                ->description($figures['adjustments'].' adjusted tickets')
                ->color($impact < 0 ? 'danger' : 'gray'),
            Stat::make('Lost-ticket fees', Format::money($figures['lost_fees']))
                ->description($figures['lost_tickets'].' lost tickets'),
        ];
    }
}
