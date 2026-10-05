<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\Statistics\DateRange;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;

/**
 * Shared by statistics widgets: the period comes from the dashboard filter, and access from view_statistics.
 */
trait ReadsPeriod
{
    /** @var array<string, mixed>|null */
    public ?array $filters = null;

    protected function period(): DateRange
    {
        return DateRange::fromFilters($this->filters);
    }

    public static function canView(): bool
    {
        return Auth::user()?->can(Permissions::VIEW_STATISTICS) ?? false;
    }
}
