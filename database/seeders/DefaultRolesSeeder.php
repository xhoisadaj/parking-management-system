<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates permissions and the three default roles.
 *
 * Uses firstOrCreate, so re-running the seeder never overwrites roles or
 * limits an admin has already edited in the panel.
 */
class DefaultRolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach ($this->roles() as $name => [$permissions, $maxDiscount]) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['max_discount_percent' => $maxDiscount],
            );

            // Admin always has every permission. Other roles get their defaults only when
            // first created, so permission edits made in the panel survive re-seeding.
            if ($name === 'Admin' || $role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * role name => [permission names, max discount percent (null = unlimited)]
     *
     * @return array<string, array{0: list<string>, 1: ?float}>
     */
    private function roles(): array
    {
        return [
            'Admin' => [Permissions::all(), null],
            'Manager' => [[
                Permissions::ISSUE_TICKET,
                Permissions::CHECKOUT,
                Permissions::ADJUST_PRICE,
                Permissions::VOID_TICKET,
                Permissions::MANAGE_TARIFFS,
                Permissions::VIEW_STATISTICS,
                Permissions::VIEW_AUDIT_LOG,
                Permissions::RECONCILE_SHIFTS,
            ], 20.0],
            'Operator' => [[
                Permissions::ISSUE_TICKET,
                Permissions::CHECKOUT,
            ], 0.0],
        ];
    }
}
