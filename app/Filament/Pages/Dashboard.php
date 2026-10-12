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

    protected static ?string $navigationLabel = 'Statistikat';

    protected static ?string $title = 'Statistikat';

    /**
     * This page claims the panel's root URL (Filament\Pages\Dashboard always does — it's
     * $routePath = '/'), so it has to stay reachable by anyone who got into the panel at all, not
     * just those with view_statistics: denying it here isn't a 403 for one page, it's the panel's
     * entire root 404ing for them. The actual content is still fully gated — every widget below
     * checks view_statistics itself (see Widgets\Concerns\ReadsPeriod::canView()), so someone
     * without it just lands on an empty page, which is also why it's hidden from the nav instead.
     */
    public static function canAccess(): bool
    {
        return true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->can(Permissions::VIEW_STATISTICS) ?? false;
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('preset')
                    ->label('Periudha')
                    ->options([
                        'today' => 'Sot',
                        'week' => 'Kjo javë',
                        'month' => 'Ky muaj',
                        'custom' => 'Interval i personalizuar',
                    ])
                    ->default('month')
                    ->native(false),
                DatePicker::make('from')
                    ->label('Nga')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('preset') === 'custom'),
                DatePicker::make('to')
                    ->label('Deri')
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
