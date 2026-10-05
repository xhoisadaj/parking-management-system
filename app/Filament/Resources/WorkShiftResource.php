<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\WorkShiftResource\Pages;
use App\Models\WorkShift;
use App\Support\Permissions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkShiftResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = WorkShift::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'work shift';

    protected static ?string $navigationLabel = 'Work shifts';

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_USERS;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->placeholder('Morning'),
            TimePicker::make('starts_at')
                ->label('Starts')
                ->seconds(false)
                ->required(),
            TimePicker::make('ends_at')
                ->label('Ends')
                ->seconds(false)
                ->required()
                ->helperText('Earlier than the start time means the shift runs past midnight.'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('starts_at')
                    ->label('Hours')
                    ->formatStateUsing(fn (WorkShift $record) => $record->hoursLabel()),
                TextColumn::make('users_count')->counts('users')->label('Operators'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (WorkShift $record) => $record->users()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkShifts::route('/'),
            'create' => Pages\CreateWorkShift::route('/create'),
            'edit' => Pages\EditWorkShift::route('/{record}/edit'),
        ];
    }
}
