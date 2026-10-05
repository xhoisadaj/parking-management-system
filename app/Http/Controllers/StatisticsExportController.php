<?php

namespace App\Http\Controllers;

use App\Exports\RowsSheet;
use App\Exports\StatisticsWorkbook;

use App\Models\Setting;
use App\Services\Statistics\DateRange;
use App\Services\Statistics\StatisticsService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads the statistics for the period chosen on the dashboard.
 * XLSX has one sheet per table. CSV contains the tickets only.
 */
class StatisticsExportController extends Controller
{
    public function __invoke(Request $request, StatisticsService $stats): BinaryFileResponse
    {
        Gate::authorize(Permissions::VIEW_STATISTICS);

        $request->validate([
            'format' => ['required', 'in:xlsx,csv'],
            'preset' => ['nullable', 'in:'.implode(',', DateRange::PRESETS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tz = Setting::current()->timezone;
        $range = $request->filled(['from', 'to'])
            ? DateRange::between(
                CarbonImmutable::parse($request->string('from'), $tz),
                CarbonImmutable::parse($request->string('to'), $tz),
                (string) $request->input('preset', 'custom'),
            )
            : DateRange::fromFilters(['preset' => $request->input('preset', 'month')]);

        $stamp = $range->from->format('Ymd').'-'.$range->to->format('Ymd');

        if ($request->input('format') === 'csv') {
            return Excel::download($this->sessionsSheet($range), "parking-tickets-{$stamp}.csv", ExcelWriter::CSV, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $workbook = new StatisticsWorkbook([
            $this->sessionsSheet($range),
            $this->revenueSheet($stats, $range),
            $this->operatorsSheet($stats, $range),
        ]);

        return Excel::download($workbook, "parking-statistics-{$stamp}.xlsx");
    }

    private function sessionsSheet(DateRange $range): RowsSheet
    {
        $tz = Setting::current()->timezone;
        $fmt = fn ($value) => $value ? CarbonImmutable::parse($value, config('app.timezone'))->setTimezone($tz)->format('Y-m-d H:i') : null;

        $rows = DB::table('parking_sessions')
            ->join('vehicle_types', 'vehicle_types.id', '=', 'parking_sessions.vehicle_type_id')
            ->leftJoin('users as entry', 'entry.id', '=', 'parking_sessions.entry_user_id')
            ->leftJoin('users as exit', 'exit.id', '=', 'parking_sessions.exit_user_id')
            ->leftJoin('users as adjuster', 'adjuster.id', '=', 'parking_sessions.adjusted_by')
            ->whereBetween('parking_sessions.entered_at', [$range->from, $range->to])
            ->orderBy('parking_sessions.entered_at')
            ->get([
                'parking_sessions.ticket_code',
                'vehicle_types.name as vehicle',
                'parking_sessions.plate',
                'parking_sessions.entered_at',
                'parking_sessions.exited_at',
                'parking_sessions.duration_minutes',
                'parking_sessions.calculated_price',
                'parking_sessions.final_price',
                'parking_sessions.status',
                'entry.name as entry_operator',
                'exit.name as exit_operator',
                'adjuster.name as adjusted_by',
                'parking_sessions.adjustment_reason',
            ]);

        return new RowsSheet(
            'Tickets',
            ['Ticket', 'Vehicle', 'Plate', 'Entered', 'Exited', 'Duration (min)', 'Calculated', 'Final', 'Status', 'Entry operator', 'Exit operator', 'Adjusted by', 'Adjustment reason'],
            $rows->map(fn ($r) => [
                $r->ticket_code,
                $r->vehicle,
                $r->plate,
                $fmt($r->entered_at),
                $fmt($r->exited_at),
                $r->duration_minutes,
                $r->calculated_price,
                $r->final_price,
                $r->status,
                $r->entry_operator,
                $r->exit_operator,
                $r->adjusted_by,
                $r->adjustment_reason,
            ])->all(),
        );
    }

    private function revenueSheet(StatisticsService $stats, DateRange $range): RowsSheet
    {
        $rows = collect($stats->revenueByVehicleType($range->from, $range->to))
            ->map(fn (array $row) => [$row['vehicle'], $row['tickets'], $row['revenue']])
            ->all();

        return new RowsSheet('Revenue by type', ['Vehicle', 'Tickets', 'Revenue'], $rows);
    }

    private function operatorsSheet(StatisticsService $stats, DateRange $range): RowsSheet
    {
        $rows = collect($stats->perUser($range->from, $range->to))
            ->map(fn (array $row) => [$row['user'], $row['issued'], $row['checkouts'], $row['revenue'], $row['adjustments'], $row['adjustment_value']])
            ->all();

        return new RowsSheet('Operators', ['Operator', 'Issued', 'Checkouts', 'Revenue', 'Adjustments', 'Adjustment value'], $rows);
    }
}
