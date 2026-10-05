<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaxDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DefaultRolesSeeder::class);
    }

    public function test_admin_has_unlimited_discount(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $this->assertNull($user->maxDiscountPercent());
    }

    public function test_manager_discount_limit_comes_from_the_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Manager');

        $this->assertSame(20.0, $user->maxDiscountPercent());
    }

    public function test_operator_cannot_discount(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Operator');

        $this->assertSame(0.0, $user->maxDiscountPercent());
    }

    public function test_user_without_roles_cannot_discount(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0.0, $user->maxDiscountPercent());
    }

    public function test_direct_permission_does_not_raise_the_discount_limit(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permissions::ADJUST_PRICE);

        $this->assertTrue($user->can(Permissions::ADJUST_PRICE));
        $this->assertSame(0.0, $user->maxDiscountPercent());
    }

    public function test_multiple_roles_use_the_highest_limit(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['Operator', 'Manager']);

        $this->assertSame(20.0, $user->maxDiscountPercent());
    }

    public function test_any_unlimited_role_makes_the_user_unlimited(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['Manager', 'Admin']);

        $this->assertNull($user->maxDiscountPercent());
    }

    public function test_changing_a_role_limit_applies_to_its_users(): void
    {
        Role::where('name', 'Manager')->update(['max_discount_percent' => 50]);

        $user = User::factory()->create();
        $user->assignRole('Manager');

        $this->assertSame(50.0, $user->fresh()->maxDiscountPercent());
    }
}
