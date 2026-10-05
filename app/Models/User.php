<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only active users with at least one admin-area permission may open the Filament panel.
     * Operators (checkout/entry only) use the operator screens instead.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyPermission([
            \App\Support\Permissions::MANAGE_TARIFFS,
            \App\Support\Permissions::MANAGE_SETTINGS,
            \App\Support\Permissions::MANAGE_USERS,
            \App\Support\Permissions::VIEW_STATISTICS,
            \App\Support\Permissions::VIEW_AUDIT_LOG,
        ]);
    }

    /**
     * Highest discount this user may apply at checkout, in percent.
     *
     * - null  = unlimited (at least one of the user's roles is unlimited)
     * - 0.0   = no discounts (including users without any role)
     * - float = the largest limit across the user's roles
     *
     * Direct permissions do not grant a discount limit; the limit comes from roles only.
     */
    public function maxDiscountPercent(): ?float
    {
        $roles = $this->roles;

        if ($roles->isEmpty()) {
            return 0.0;
        }

        $limits = $roles->map(fn (Role $role) => $role->max_discount_percent);

        if ($limits->contains(fn ($limit) => $limit === null)) {
            return null;
        }

        return (float) $limits->max();
    }
}
