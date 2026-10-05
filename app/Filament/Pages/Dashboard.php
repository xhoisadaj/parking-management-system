<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ImpactOverview;
use App\Filament\Widgets\LiveOccupancyOverview;
use App\Filament\Widgets\OccupancyHeatmap;
use App\Filament\Widgets\RevenueByVehicleChart;
use App\Filament\Widgets\RevenueOverview;
use App\Filament\Widgets\UserPerformance;
use App\Support\Permissions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Illuminate\Support\Facades\Auth;

/**
 * Statistics dashboard. The period filter is shared with every widget through `$filters`.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $view = 'filament.pages.statistics-dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Statistics';

    protected static ?string $title = 'Statistics';

    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::VIEW_STATISTICS) ?? false;
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('preset')
                    ->label('Period')
                    ->options([
                        'today' => 'Today',
                        'week' => 'This week',
                        'month' => 'This month',
                        'custom' => 'Custom range',
                    ])
                    ->default('month')
                    ->native(false),
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
                DatePicker::make('to')
                    ->label('To')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
            ])
            ->columns(3);
    }

    /** @return array<int, class-string> */
    public function getWidgets(): array
    {
        return [
            RevenueOverview::class,
            ImpactOverview::class,
            LiveOccupancyOverview::class,
            RevenueByVehicleChart::class,
            OccupancyHeatmap::class,
            UserPerformance::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public function exportUrl(string $format): string
    {
        $range = \App\Services\Statistics\DateRange::fromFilters($this->filters);

        return route('statistics.export', [
            'format' => $format,
            'preset' => $range->preset,
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
        ]);
    }
}
