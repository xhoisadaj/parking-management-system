<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use App\Support\Permissions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TariffResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Tariff::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Konfigurimi';

    protected static ?string $navigationLabel = 'Tarifat';

    protected static ?string $pluralModelLabel = 'tarifat';

    protected static ?string $modelLabel = 'tarifë';

    protected static ?int $navigationSort = 2;

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_TARIFFS;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('vehicle_type_id')
                ->label('Lloji i mjetit')
                ->relationship('vehicleType', 'name')
                ->required()
                ->preload(),
            TextInput::make('name')->label('Emri')
                ->default('Standard')
                ->maxLength(100),
            TextInput::make('billing_unit_minutes')
                ->label('Njësia e faturimit (minuta)')
                ->helperText('Vlera të zakonshme: 1, 15, 60.')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(1440)
                ->default(60),
            TextInput::make('price_per_unit')
                ->label('Çmimi për njësi')
                ->numeric()
                ->required()
                ->minValue(0)
                ->step(0.01),
            TextInput::make('first_unit_price')
                ->label('Çmimi i njësisë së parë (opsionale)')
                ->helperText('Zëvendëson vetëm çmimin e njësisë së parë të faturimit.')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->nullable(),
            TextInput::make('grace_minutes')
                ->label('Periudha falas (minuta)')
                ->helperText('Qëndrimet kaq të shkurtra ose më të shkurtra janë falas.')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(0)
                ->default(0),
            TextInput::make('daily_max')
                ->label('Maksimumi ditor (opsionale)')
                ->helperText('Pagesa për çdo ditë kalendarike kufizohet në këtë shumë.')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->nullable(),
            Hidden::make('rounding')->default('up'),
            DatePicker::make('active_from')
                ->label('E vlefshme nga')
                ->native(false)
                ->nullable(),
            DatePicker::make('active_to')
                ->label('E vlefshme deri')
                ->native(false)
                ->nullable()
                ->afterOrEqual('active_from'),
            Toggle::make('is_active')
                ->label('Aktive')
                ->default(true),
            Repeater::make('timeBands')
                ->label('Intervalet kohore (p.sh. tarifa e natës)')
                ->helperText('Njësitë që fillojnë brenda një intervali përdorin çmimin e tij. Një interval që mbaron para se të fillojë kalon mesnatën.')
                ->relationship()
                ->orderColumn('position')
                ->collapsible()
                ->defaultItems(0)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('label')->label('Etiketa')->maxLength(100),
                    TimePicker::make('starts_at')
                        ->label('Nga')
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('ends_at')
                        ->label('Deri')
                        ->seconds(false)
                        ->required(),
                    TextInput::make('price_per_unit')
                        ->label('Çmimi për njësi')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->required(),
                ])
                ->columns(4),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('vehicleType.name')->label('Mjeti')->sortable(),
                TextColumn::make('name')->label('Emri')->searchable(),
                TextColumn::make('price_per_unit')->label('Çmimi')->sortable(),
                TextColumn::make('billing_unit_minutes')->label('Njësia (min)'),
                TextColumn::make('grace_minutes')->label('Falas (min)')->toggleable(),
                TextColumn::make('daily_max')->label('Maks. ditor')->placeholder('—')->toggleable(),
                TextColumn::make('active_from')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('active_to')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Aktive')->boolean(),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTariffs::route('/'),
            'create' => Pages\CreateTariff::route('/create'),
            'edit' => Pages\EditTariff::route('/{record}/edit'),
        ];
    }
}
