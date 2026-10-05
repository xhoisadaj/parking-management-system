<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesWithPermission;
use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class RoleResource extends Resource
{
    use AuthorizesWithPermission;

    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'role';

    protected static function requiredPermission(): string
    {
        return Permissions::MANAGE_USERS;
    }

    /**
     * The discount limit is money-related, so only users who can manage settings
     * (Admin by default) may change it, even though anyone with manage_users can open this screen.
     */
    private static function canEditDiscountLimit(): bool
    {
        return Auth::user()?->can(Permissions::MANAGE_SETTINGS) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        // The Admin role is the only way to recover access, so it cannot be deleted.
        return static::canViewAny() && $record->name !== 'Admin';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Hidden::make('guard_name')->default('web'),
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true),
            TextInput::make('max_discount_percent')
                ->label('Max discount (%)')
                ->helperText('0 = no discounts. Leave empty for unlimited.')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->nullable()
                ->disabled(fn () => ! self::canEditDiscountLimit())
                ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (float) $state),
            CheckboxList::make('permissions')
                ->relationship('permissions', 'name')
                ->columns(2)
                ->bulkToggleable(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('max_discount_percent')
                    ->label('Max discount')
                    ->formatStateUsing(fn ($state) => $state === null ? 'Unlimited' : rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),
                TextColumn::make('permissions.name')->label('Permissions')->badge()->limitList(4),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
