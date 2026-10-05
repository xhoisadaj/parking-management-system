<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\DateRange;
use App\Services\Statistics\StatisticsService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueOverview extends StatsOverviewWidget
{
    use ReadsPeriod;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $stats = app(StatisticsService::class);
        $selected = $this->period();
        $now = CarbonImmutable::now();

        $periods = [
            'Today' => DateRange::fromFilters(['preset' => 'today'], $now),
            'This week' => DateRange::fromFilters(['preset' => 'week'], $now),
            'This month' => DateRange::fromFilters(['preset' => 'month'], $now),
        ];

        $cards = [];

        foreach ($periods as $label => $range) {
            $cards[] = Stat::make($label, Format::money($stats->revenue($range->from, $range->to)))
                ->description('Collected');
        }

        $stay = $stats->averageStayMinutes($selected->from, $selected->to);

        return [
            ...$cards,
            Stat::make($selected->label(), Format::money($stats->revenue($selected->from, $selected->to)))
                ->description($stay === null ? 'No stays yet' : 'Average stay '.Format::duration((int) round($stay))),
        ];
    }
}
