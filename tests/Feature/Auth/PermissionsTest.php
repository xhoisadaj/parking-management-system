<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DefaultRolesSeeder::class);
    }

    public function test_admin_role_has_every_permission(): void
    {
        $admin = Role::findByName('Admin', 'web');

        foreach (Permissions::all() as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission), "Admin missing {$permission}");
        }
    }

    public function test_operator_role_is_limited_to_entry_and_checkout(): void
    {
        $operator = Role::findByName('Operator', 'web');

        $this->assertEqualsCanonicalizing(
            [Permissions::ISSUE_TICKET, Permissions::CHECKOUT],
            $operator->permissions->pluck('name')->all(),
        );
    }

    public function test_operator_cannot_adjust_price(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->assertFalse($operator->can(Permissions::ADJUST_PRICE));
    }

    public function test_permission_can_be_granted_directly_to_one_user(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $operator->givePermissionTo(Permissions::ADJUST_PRICE);

        $this->assertTrue($operator->can(Permissions::ADJUST_PRICE));

        $otherOperator = User::factory()->create();
        $otherOperator->assignRole('Operator');

        $this->assertFalse($otherOperator->can(Permissions::ADJUST_PRICE), 'Direct grant must not leak to other users');
    }

    public function test_direct_permission_change_takes_effect_immediately(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Operator');

        $this->assertFalse($user->can(Permissions::VOID_TICKET));

        $user->givePermissionTo(Permissions::VOID_TICKET);
        $user->refresh();

        $this->assertTrue($user->can(Permissions::VOID_TICKET));
    }

    public function test_seeder_is_idempotent_and_keeps_admin_edits(): void
    {
        Role::where('name', 'Manager')->update(['max_discount_percent' => 35]);

        $this->seed(DefaultRolesSeeder::class);

        $this->assertSame(1, Role::where('name', 'Manager')->count());
        $this->assertSame(35.0, (float) Role::where('name', 'Manager')->first()->max_discount_percent);
    }
}
