<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Permissions;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'work_shift_id',
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

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    /**
     * Only active users with at least one admin-area permission may open the Filament panel.
     * Operators (checkout/entry only) use the operator screens instead.
     */
    /**
     * Two separate panels, two separate rules:
     *  - admin: Admin only. Deliberately role-based and nothing wider — this is the full
     *    everything-panel (Users, Roles, Settings included), so it stays off-limits to anyone
     *    who isn't actually an administrator, Manager included.
     *  - menaxhimi: open to anyone — Manager or otherwise — who holds at least one of the
     *    admin-area permissions (Permissions::adminAreaPermissions()). Each page inside is still
     *    separately gated by its own specific permission, so holding one doesn't unlock the rest;
     *    this only decides whether the panel itself is worth entering at all.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->hasRole('Admin'),
            'menaxhimi' => collect(Permissions::adminAreaPermissions())->contains(fn (string $permission) => $this->can($permission)),
            default => false,
        };
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
