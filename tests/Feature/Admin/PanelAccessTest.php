<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageSettings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DefaultRolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DefaultRolesSeeder::class, SettingsSeeder::class]);
    }

    private function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_can_open_the_panel(): void
    {
        $this->actingAs($this->userWith('Admin'))->get('/admin')->assertOk();
    }

    public function test_operator_cannot_open_the_panel(): void
    {
        $this->actingAs($this->userWith('Operator'))->get('/admin')->assertForbidden();
    }

    public function test_deactivated_admin_cannot_open_the_panel(): void
    {
        $admin = $this->userWith('Admin');
        $admin->update(['is_active' => false]);

        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_manager_can_manage_tariffs_but_not_users_or_settings(): void
    {
        $manager = $this->userWith('Manager');

        $this->actingAs($manager)->get('/admin/tariffs')->assertOk();
        $this->actingAs($manager)->get('/admin/vehicle-types')->assertOk();
        $this->actingAs($manager)->get('/admin/users')->assertForbidden();
        $this->actingAs($manager)->get('/admin/roles')->assertForbidden();
        $this->actingAs($manager)->get('/admin/manage-settings')->assertForbidden();
    }

    public function test_manager_can_view_the_audit_log(): void
    {
        $this->actingAs($this->userWith('Manager'))->get('/admin/audit-logs')->assertOk();
    }

    public function test_operator_cannot_reach_configuration_pages(): void
    {
        $operator = $this->userWith('Operator');

        $this->actingAs($operator)->get('/admin/tariffs')->assertForbidden();
        $this->actingAs($operator)->get('/admin/vehicle-types')->assertForbidden();
        $this->actingAs($operator)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_user_with_a_direct_permission_can_open_its_section(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('view_audit_log');

        $this->actingAs($user)->get('/admin/audit-logs')->assertOk();
    }

    public function test_settings_page_is_admin_only(): void
    {
        $this->assertFalse($this->actingAsAndCheck($this->userWith('Manager'), fn () => ManageSettings::canAccess()));
        $this->assertTrue($this->actingAsAndCheck($this->userWith('Admin'), fn () => ManageSettings::canAccess()));
    }

    public function test_admin_saving_settings_updates_the_row_and_writes_an_audit_entry(): void
    {
        $admin = $this->userWith('Admin');
        Setting::current()->update(['parking_name' => 'City Center Parking']);

        $this->actingAs($admin);

        Livewire::test(ManageSettings::class)
            ->set('data.parking_name', 'Renamed Parking')
            ->set('data.total_capacity', 75)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Parking', Setting::current()->parking_name);
        $this->assertSame(75.0, (float) Setting::current()->total_capacity);

        $entry = AuditLog::query()->where('event', 'settings.updated')->first();
        $this->assertNotNull($entry);
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('City Center Parking', $entry->old_values['settings']['parking_name'] ?? null);
        $this->assertSame('Renamed Parking', $entry->new_values['settings']['parking_name'] ?? null);
    }

    /**
     * Runs $callback as $user and returns its result. Keeps the auth state contained to one check.
     */
    private function actingAsAndCheck(User $user, callable $callback): mixed
    {
        $this->actingAs($user);

        return $callback();
    }
}
