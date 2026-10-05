<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
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

    protected static ?string $navigationGroup = 'Administration';

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
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->password()
                ->revealable()
                ->minLength(8)
                ->maxLength(255)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText('Leave empty to keep the current password.'),
            Toggle::make('is_active')
                ->label('Account active')
                ->default(true)
                ->disabled(fn (?Model $record) => self::isSelf($record)),
            Select::make('roles')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->disabled(fn (?Model $record) => self::isSelf($record))
                ->helperText('Roles grant a set of permissions and a discount limit.'),
            CheckboxList::make('permissions')
                ->label('Extra permissions for this user only')
                ->relationship('permissions', 'name')
                ->columns(2)
                ->helperText('Added on top of the role permissions. Use sparingly.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->label('Roles')->badge(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([
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
