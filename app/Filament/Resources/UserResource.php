<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Pages\DailyHistory;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administrimi';

    protected static ?string $navigationLabel = 'Përdoruesit';

    protected static ?string $pluralModelLabel = 'përdoruesit';

    protected static ?string $modelLabel = 'përdorues';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_USERS;
    }

    /**
     * Editing yourself could lock you out (removing your own role or deactivating
     * your account), so those fields are read-only for your own record.
     */
    private static function isSelf(?Model $record): bool
    {
        return $record !== null && $record->is(Auth::user());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Emri')
                ->required()
                ->maxLength(255),
            TextInput::make('email')->label('Email-i')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('password')->label('Fjalëkalimi')
                ->password()
                ->revealable()
                ->minLength(8)
                ->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText('Lëreni bosh për të mbajtur fjalëkalimin aktual.'),
            Toggle::make('is_active')
                ->label('Llogaria aktive')
                ->default(true)
                ->disabled(fn (?Model $record) => self::isSelf($record)),
            Select::make('workShift')
                ->label('Turni i punës')
                ->relationship('workShift', 'name')
                ->placeholder('Pa turn të caktuar')
                ->nullable()
                ->helperText('Turni i caktuar i këtij operatori. Shfaqet në ekranin e turnit dhe përdoret në verifikim.'),
            Select::make('roles')->label('Rolet')
                ->relationship('roles', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => Permissions::roleLabel($record->name))
                ->multiple()
                ->preload()
                ->disabled(fn (?Model $record) => self::isSelf($record))
                ->helperText('Rolet japin një grup lejesh dhe një limit zbritjeje.'),
            CheckboxList::make('permissions')
                ->label('Leje shtesë vetëm për këtë përdorues')
                ->relationship('permissions', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => Permissions::label($record->name))
                ->columns(2)
                ->helperText('Shtohen mbi lejet e rolit. Përdoreni me kujdes.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Emri')->searchable()->sortable(),
                TextColumn::make('email')->label('Email-i')->searchable(),
                TextColumn::make('roles.name')->label('Rolet')->badge()->formatStateUsing(fn ($state) => Permissions::roleLabel($state)),
                TextColumn::make('workShift.name')->label('Turni')->placeholder('—'),
                IconColumn::make('is_active')->label('Aktive')->boolean(),
            ])
            ->actions([
                Action::make('history')
                    ->label('Historiku')
                    ->icon('heroicon-o-calendar-days')
                    ->url(fn (User $record) => DailyHistory::getUrl(['operator' => $record->id])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
