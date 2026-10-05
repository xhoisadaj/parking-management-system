<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsPeriod;
use App\Services\Statistics\StatisticsService;
use Filament\Widgets\Widget;

class UserPerformance extends Widget
{
    use ReadsPeriod;

    protected static string $view = 'filament.widgets.user-performance';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $period = $this->period();

        return [
            'rows' => app(StatisticsService::class)->perUser($period->from, $period->to),
            'label' => $period->label(),
        ];
    }
}
