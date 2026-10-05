<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\VehicleTypeResource\Pages;
use App\Models\VehicleType;
use App\Support\Permissions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class VehicleTypeResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = VehicleType::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'vehicle type';

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_TARIFFS;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, ?string $state, string $operation) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(100)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->helperText('Used internally. Changing it does not affect existing tickets.'),
            TextInput::make('spots_used')
                ->label('Spots used per vehicle')
                ->helperText('Share of the capacity pool one vehicle takes, e.g. 0.5 for motorbikes.')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->maxValue(99.99)
                ->step(0.01),
            TextInput::make('dedicated_capacity')
                ->label('Dedicated capacity (vehicles)')
                ->helperText('Optional maximum number of this vehicle type parked at once. Leave empty for no separate cap.')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->nullable(),
            TextInput::make('sort_order')
                ->numeric()
                ->integer()
                ->default(0),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Inactive types do not appear on the entry screen.')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('spots_used')->label('Spots')->sortable(),
                TextColumn::make('dedicated_capacity')->label('Dedicated cap')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('sort_order')->label('Order')->toggleable(isToggledHiddenByDefault: true),
            ])
            // No delete: types referenced by tariffs or past tickets are deactivated instead.
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleTypes::route('/'),
            'create' => Pages\CreateVehicleType::route('/create'),
            'edit' => Pages\EditVehicleType::route('/{record}/edit'),
        ];
    }
}
