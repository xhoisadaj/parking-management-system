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
            Stat::make('Të llogaritura', Format::money($figures['calculated']))
                ->description('Biletat e paguara sipas tarifës'),
            Stat::make('Reale', Format::money($figures['actual']))
                ->description('Çfarë u arkëtua në fakt')
                ->color($impact < 0 ? 'warning' : 'success'),
            Stat::make('Ndikimi i ndryshimeve', $impactText)
                ->description($figures['adjustments'].' bileta të ndryshuara')
                ->color($impact < 0 ? 'danger' : 'gray'),
            Stat::make('Tarifat e biletave të humbura', Format::money($figures['lost_fees']))
                ->description($figures['lost_tickets'].' bileta të humbura'),
        ];
    }
}
