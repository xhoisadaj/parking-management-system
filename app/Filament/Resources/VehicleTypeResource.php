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

    protected static ?string $navigationGroup = 'Konfigurimi';

    protected static ?string $navigationLabel = 'Llojet e mjeteve';

    protected static ?string $pluralModelLabel = 'llojet e mjeteve';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'lloj mjeti';

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_TARIFFS;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Emri')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, ?string $state, string $operation) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')->label('Slug')
                ->required()
                ->maxLength(100)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->helperText('Përdoret brenda sistemit. Ndryshimi nuk prek biletat ekzistuese.'),
            TextInput::make('spots_used')
                ->label('Vende të përdorura për mjet')
                ->helperText('Pjesa e fondit të kapacitetit që zë një mjet, p.sh. 0,5 për motorët.')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->maxValue(99.99)
                ->step(0.01),
            TextInput::make('dedicated_capacity')
                ->label('Kapacitet i dedikuar (mjete)')
                ->helperText('Numri maksimal opsional i mjeteve të këtij lloji të parkuara njëkohësisht. Lëreni bosh për pa kufi të veçantë.')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->nullable(),
            TextInput::make('sort_order')->label('Renditja')
                ->numeric()
                ->integer()
                ->default(0),
            Toggle::make('is_active')
                ->label('Aktive')
                ->helperText('Llojet joaktive nuk shfaqen në ekranin e hyrjes.')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Emri')->searchable()->sortable(),
                TextColumn::make('spots_used')->label('Vende')->sortable(),
                TextColumn::make('dedicated_capacity')->label('Kufiri i dedikuar')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Aktive')->boolean(),
                TextColumn::make('sort_order')->label('Renditja')->toggleable(isToggledHiddenByDefault: true),
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
