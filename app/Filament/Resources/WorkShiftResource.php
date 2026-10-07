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

    protected static ?string $navigationGroup = 'Konfigurimi';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'turn pune';

    protected static ?string $navigationLabel = 'Turnet e punës';

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_USERS;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Emri')
                ->required()
                ->maxLength(100)
                ->placeholder('Mëngjes'),
            TimePicker::make('starts_at')
                ->label('Fillon')
                ->seconds(false)
                ->required(),
            TimePicker::make('ends_at')
                ->label('Mbaron')
                ->seconds(false)
                ->required()
                ->helperText('Një orë mbarimi më herët se fillimi do të thotë që turni kalon mesnatën.'),
            Toggle::make('is_active')
                ->label('Aktive')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('name')->label('Emri')->searchable(),
                TextColumn::make('starts_at')
                    ->label('Orari')
                    ->formatStateUsing(fn (WorkShift $record) => $record->hoursLabel()),
                TextColumn::make('users_count')->counts('users')->label('Operatorët'),
                IconColumn::make('is_active')->label('Aktive')->boolean(),
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
