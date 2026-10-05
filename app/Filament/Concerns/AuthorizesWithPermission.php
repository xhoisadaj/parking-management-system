<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Gates a Filament resource on a single permission name. Used instead of
 * Filament's default "everyone who can open the panel" behaviour.
 *
 * Set `canCreate()`, `canEdit()` and `canDelete()` to false in a resource that
 * should be read-only by overriding `allowsWrites()`.
 */
trait AuthorizesWithPermission
{
    abstract protected static function requiredPermission(): string;

    protected static function allowsWrites(): bool
    {
        return true;
    }

    protected static function userHas(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::userHas(static::requiredPermission());
    }

    public static function canCreate(): bool
    {
        return static::allowsWrites() && static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowsWrites() && static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowsWrites() && static::canViewAny();
    }
}
