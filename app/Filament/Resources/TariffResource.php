<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use App\Support\Permissions;
use Closure;
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
                ->helperText('Vlera të zakonshme: 1, 15, 60. Shpërfillet nëse shtoni shkallë kohëzgjatjeje më poshtë.')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(1)
                ->maxValue(1440)
                ->default(60),
            TextInput::make('price_per_unit')
                ->label('Çmimi për njësi')
                ->helperText('Shpërfillet nëse shtoni shkallë kohëzgjatjeje më poshtë.')
                ->numeric()
                ->required()
                ->minValue(0)
                ->step(0.01),
            TextInput::make('first_unit_price')
                ->label('Çmimi i njësisë së parë (opsionale)')
                ->helperText('Zëvendëson vetëm çmimin e njësisë së parë të faturimit. Shpërfillet nëse shtoni shkallë kohëzgjatjeje më poshtë.')
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
                ->helperText('Pagesa për çdo ditë kufizohet në këtë shumë. Një ditë është 24 orë nga hyrja, jo dita kalendarike.')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->nullable(),
            TextInput::make('long_stay_threshold_days')
                ->label('Tarifë afatgjatë pas (ditë, opsionale)')
                ->helperText('P.sh. parkimi i aeroportit: nga kjo ditë e tutje, mbarë qëndrimi faturohet me çmimin ditor më poshtë në vend të njësive orare/maksimumit ditor.')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->nullable(),
            TextInput::make('long_stay_daily_price')
                ->label('Çmimi ditor afatgjatë (opsionale)')
                ->helperText('Përdoret vetëm kur qëndrimi arrin pragun më sipër.')
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
            Repeater::make('durationTiers')
                ->label('Shkallë çmimi sipas kohëzgjatjes (opsionale)')
                ->helperText('Një çmim fiks për çdo shkallë kohëzgjatjeje, p.sh. 100 lekë deri në 2 orë, 200 lekë deri në 10 orë, pastaj çmimi ditor. Nëse shtoni të paktën një shkallë këtu, ato zëvendësojnë plotësisht njësinë/çmimin për njësi/çmimin e njësisë së parë/intervalet kohore më sipër për këtë tarifë. Lëreni "Deri në (minuta)" bosh vetëm te shkalla e fundit — ajo mbulon çdo kohë mbi shkallët e tjera (p.sh. çmimi i ditës së plotë).')
                ->relationship()
                ->orderColumn('position')
                ->collapsible()
                ->defaultItems(0)
                ->columnSpanFull()
                // Every stay has to land in some tier. Without exactly one open-ended tier, a stay
                // longer than every cutoff has no price of its own and PriceCalculator falls back
                // to the highest cutoff's price — silently undercharging. Two tiers with the same
                // cutoff would leave which price applies down to their order.
                ->rules([fn (): Closure => function (string $attribute, $value, Closure $fail) {
                    $cutoffs = collect($value ?? [])->pluck('up_to_minutes')
                        ->map(fn ($minutes) => $minutes === null || $minutes === '' ? null : (int) $minutes);

                    if ($cutoffs->isEmpty()) {
                        return;
                    }

                    $open = $cutoffs->filter(fn (?int $minutes) => $minutes === null)->count();

                    if ($open !== 1) {
                        $fail($open === 0
                            ? 'Shkalla e fundit duhet ta ketë "Deri në (minuta)" bosh, që të mbulojë çdo qëndrim më të gjatë.'
                            : 'Vetëm një shkallë mund ta ketë "Deri në (minuta)" bosh.');

                        return;
                    }

                    if ($cutoffs->filter()->duplicates()->isNotEmpty()) {
                        $fail('Dy shkallë nuk mund të kenë të njëjtin "Deri në (minuta)".');
                    }
                }])
                ->schema([
                    TextInput::make('label')->label('Etiketa (opsionale)')->maxLength(100),
                    TextInput::make('up_to_minutes')
                        ->label('Deri në (minuta)')
                        ->helperText('Bosh = shkalla e fundit, e hapur.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->nullable(),
                    TextInput::make('price')
                        ->label('Çmimi fiks')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->required(),
                ])
                ->columns(3),
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
                TextColumn::make('long_stay_threshold_days')->label('Afatgjatë pas (ditë)')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('long_stay_daily_price')->label('Çmimi ditor afatgjatë')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('durationTiers_count')->counts('durationTiers')->label('Shkallë kohëzgjatjeje')->placeholder('—')->toggleable(),
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
