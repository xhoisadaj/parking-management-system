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

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_TARIFFS;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('vehicle_type_id')
                ->label('Vehicle type')
                ->relationship('vehicleType', 'name')
                ->required()
                ->preload(),
            TextInput::make('name')
                ->default('Standard')
                ->maxLength(100),
            TextInput::make('billing_unit_minutes')
                ->label('Billing unit (minutes)')
                ->helperText('Common values: 1, 15, 60.')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(1440)
                ->default(60),
            TextInput::make('price_per_unit')
                ->label('Price per unit')
                ->numeric()
                ->required()
                ->minValue(0)
                ->step(0.01),
            TextInput::make('first_unit_price')
                ->label('First unit price (optional)')
                ->helperText('Replaces the price of the first billing unit only.')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->nullable(),
            TextInput::make('grace_minutes')
                ->label('Grace period (minutes)')
                ->helperText('Stays this short or shorter are free.')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(0)
                ->default(0),
            TextInput::make('daily_max')
                ->label('Daily maximum (optional)')
                ->helperText('Charges for each calendar day are capped at this amount.')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->nullable(),
            Hidden::make('rounding')->default('up'),
            DatePicker::make('active_from')
                ->label('Valid from')
                ->native(false)
                ->nullable(),
            DatePicker::make('active_to')
                ->label('Valid to')
                ->native(false)
                ->nullable()
                ->afterOrEqual('active_from'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
            Repeater::make('timeBands')
                ->label('Time bands (e.g. night rate)')
                ->helperText('Units starting inside a band use its price. A band with an end time before its start time crosses midnight.')
                ->relationship()
                ->orderColumn('position')
                ->collapsible()
                ->defaultItems(0)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('label')->maxLength(100),
                    TimePicker::make('starts_at')
                        ->label('From')
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('ends_at')
                        ->label('Until')
                        ->seconds(false)
                        ->required(),
                    TextInput::make('price_per_unit')
                        ->label('Price per unit')
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
                TextColumn::make('vehicleType.name')->label('Vehicle')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('price_per_unit')->label('Price')->sortable(),
                TextColumn::make('billing_unit_minutes')->label('Unit (min)'),
                TextColumn::make('grace_minutes')->label('Grace (min)')->toggleable(),
                TextColumn::make('daily_max')->label('Daily max')->placeholder('—')->toggleable(),
                TextColumn::make('active_from')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('active_to')->date()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
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
