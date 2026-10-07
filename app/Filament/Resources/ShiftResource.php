<?php

namespace App\Filament\Resources;

use App\Exceptions\ParkingException;
use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\ShiftResource\Pages;
use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\Permissions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                TextColumn::make('tickets_issued')->label('Të lëshuara'),
                TextColumn::make('checkouts')->label('Arkëtime'),
                TextColumn::make('cash_collected')->label('Të arkëtuara')->numeric(decimalPlaces: 2),
                TextColumn::make('cash_counted')->label('Të numëruara')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('difference')
                    ->label('Diferenca')
                    ->state(fn (Shift $record) => $record->cash_counted === null
                        ? null
                        : round((float) $record->cash_counted - (float) $record->cash_collected, 2))
                    ->numeric(decimalPlaces: 2)
                    ->color(fn ($state) => $state === null ? null : (abs((float) $state) < 0.005 ? 'success' : 'danger'))
                    ->placeholder('—'),
                TextColumn::make('reconciledBy.name')->label('Verifikuar nga')->placeholder('Në pritje'),
            ])
            ->actions([
                Action::make('reconcile')
                    ->label('Konfirmo arkën')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Shift $record) => $record->closed_at !== null && $record->reconciled_at === null)
                    ->form([
                        TextInput::make('cash_counted')
                            ->label('Para të numëruara')
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
}
