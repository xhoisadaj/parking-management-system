<?php

namespace App\Filament\Resources;

use App\Exceptions\ParkingException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\ShiftResource\Pages;
use App\Models\ParkingSession;
use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\Format;
use App\Support\Permissions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ShiftResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Operacionet';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'turn';

    protected static ?string $navigationLabel = 'Verifikimi i arkës';

    protected static function requiredPermission(): string
    {
        return Permissions::RECONCILE_SHIFTS;
    }

    /** Shifts are created and closed by operators on the operator screens, never here. */
    protected static function allowsWrites(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('closed_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Operatori')->searchable(),
                TextColumn::make('workShift.name')->label('Turni i punës')->placeholder('—'),
                TextColumn::make('opened_at')->label('Hapur')->dateTime('d M H:i'),
                TextColumn::make('closed_at')->label('Mbyllur')->dateTime('d M H:i')->placeholder('Hapur'),
                TextColumn::make('opening_cash')
                    ->label('Paraja fillestare')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('Pa deklaruar')
                    ->color(fn (?string $state) => $state === null ? 'warning' : null)
                    ->toggleable(),
                TextColumn::make('tickets_issued')->label('Të lëshuara'),
                TextColumn::make('checkouts')->label('Arkëtime'),
                TextColumn::make('cash_collected')->label('Të arkëtuara')->numeric(decimalPlaces: 2),
                TextColumn::make('expected')
                    ->label('Pritej gjithsej')
                    ->state(fn (Shift $record) => $record->expectedCash())
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('closing_cash_declared')
                    ->label('Deklaruar nga operatori')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('cash_counted')->label('Të numëruara (menaxheri)')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('difference')
                    ->label('Diferenca')
                    ->state(fn (Shift $record) => $record->cash_counted === null
                        ? null
                        : round((float) $record->cash_counted - $record->expectedCash(), 2))
                    ->numeric(decimalPlaces: 2)
                    ->color(fn ($state) => $state === null ? null : (abs((float) $state) < 0.005 ? 'success' : 'danger'))
                    ->placeholder('—'),
                TextColumn::make('reconciledBy.name')->label('Verifikuar nga')->placeholder('Në pritje'),
            ])
            ->actions([
                // The itemized checkouts behind "Të arkëtuara" — the same query ShiftService::summary()
                // sums, so its total always matches the column exactly. This is the actual answer to
                // "is what the operator reported correct": every ticket, price, and who closed it.
                Action::make('view_tickets')
                    ->label('Shiko biletat')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalHeading(fn (Shift $record) => 'Biletat e turnit — '.$record->user?->name)
                    ->modalContent(fn (Shift $record) => static::ticketsModalContent($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Mbyll')
                    ->modalWidth('2xl'),
                Action::make('reconcile')
                    ->label('Konfirmo arkën')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Shift $record) => $record->closed_at !== null && $record->reconciled_at === null)
                    ->fillForm(fn (Shift $record) => ['cash_counted' => $record->expectedCash()])
                    ->form([
                        Placeholder::make('expected_breakdown')
                            ->label('Pritej në arkë')
                            ->content(fn (Shift $record) => new HtmlString(sprintf(
                                '%s (fillestare) + %s (arkëtuar) = <strong>%s</strong>',
                                e(Format::money((float) ($record->opening_cash ?? 0))),
                                e(Format::money((float) $record->cash_collected)),
                                e(Format::money($record->expectedCash())),
                            )))
                            ->helperText(fn (Shift $record) => $record->opening_cash === null
                                ? 'Ky turn u hap pa deklaruar paranë fillestare — llogaritja më sipër e supozon 0.'
                                : null),
                        Placeholder::make('operator_declared')
                            ->label('Operatori deklaroi (në mbyllje)')
                            ->content(fn (Shift $record) => $record->closing_cash_declared === null
                                ? 'Nuk u deklarua'
                                : Format::money((float) $record->closing_cash_declared)),
                        TextInput::make('cash_counted')
                            ->label('Para të numëruara')
                            ->helperText('Shuma që keni numëruar fizikisht në arkë.')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.01),
                        Textarea::make('note')
                            ->label('Shënim (opsionale)')
                            ->rows(2),
                    ])
                    ->action(function (Shift $record, array $data, ShiftService $shifts) {
                        try {
                            $shifts->reconcile($record, auth()->user(), (float) $data['cash_counted'], $data['note'] ?? null);
                        } catch (ParkingException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Turni u konfirmua')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShifts::route('/'),
        ];
    }

    /**
     * Every ticket behind this shift's "Të lëshuara" and "Të arkëtuara" figures, queried exactly
     * the way ShiftService::summary() computes them — same user, same window, same statuses —
     * so what's shown here always reconciles to the numbers on the row, ticket by ticket.
     */
    private static function ticketsModalContent(Shift $record): HtmlString
    {
        $from = $record->opened_at;
        $to = $record->closed_at; // null while the shift is still open — "everything since it opened" counts

        $within = fn (Builder $query, string $column) => $to === null
            ? $query->where($column, '>=', $from)
            : $query->whereBetween($column, [$from, $to]);

        $issuedCount = $within(
            ParkingSession::query()->where('entry_user_id', $record->user_id),
            'entered_at',
        )->count();

        $checkouts = $within(
            ParkingSession::query()
                ->where('exit_user_id', $record->user_id)
                ->whereIn('status', [ParkingSession::STATUS_PAID, ParkingSession::STATUS_LOST])
                ->with('vehicleType'),
            'exited_at',
        )->orderBy('exited_at')->get();

        $rows = '';

        foreach ($checkouts as $ticket) {
            $adjusted = $ticket->adjusted_by !== null
                ? ' <span class="text-amber-700">(ndryshuar'.($ticket->adjustment_reason ? ': '.e($ticket->adjustment_reason) : '').')</span>'
                : '';

            $rows .= sprintf(
                '<tr class="border-b border-gray-100"><td class="py-1.5 pr-3 font-mono">%s</td><td class="py-1.5 pr-3">%s%s</td><td class="py-1.5 pr-3">%s</td><td class="py-1.5 text-right">%s</td></tr>',
                e($ticket->ticket_code),
                e($ticket->vehicleType->name.($ticket->plate ? ' · '.$ticket->plate : '')),
                $adjusted,
                e(Format::dateTime($ticket->exited_at)),
                e(Format::amount((float) $ticket->final_price, 2)),
            );
        }

        if ($checkouts->isEmpty()) {
            $rows = '<tr><td colspan="4" class="py-3 text-gray-500">Asnjë arkëtim në këtë turn.</td></tr>';
        }

        $total = e(Format::amount((float) $checkouts->sum('final_price'), 2));

        $openingLabel = $record->opening_cash === null
            ? 'pa deklaruar'
            : e(Format::amount((float) $record->opening_cash, 2));

        return new HtmlString(<<<HTML
            <div class="space-y-3 text-sm">
                <p>Paraja fillestare: <strong>{$openingLabel}</strong> · Biletat e lëshuara: <strong>{$issuedCount}</strong></p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-gray-300 text-xs uppercase text-gray-500">
                                <th class="py-1.5 pr-3">Bileta</th>
                                <th class="py-1.5 pr-3">Mjeti</th>
                                <th class="py-1.5 pr-3">Dalja</th>
                                <th class="py-1.5 text-right">Çmimi</th>
                            </tr>
                        </thead>
                        <tbody>{$rows}</tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-900 font-semibold">
                                <td class="py-1.5" colspan="3">Totali</td>
                                <td class="py-1.5 text-right">{$total}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            HTML);
    }
}
