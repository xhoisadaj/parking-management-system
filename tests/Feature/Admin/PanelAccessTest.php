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

    public function test_manager_cannot_open_admin_at_all(): void
    {
        // /admin is Admin-only now — Manager's whole admin-area presence lives at /menaxhimi
        // instead (see the tests below), even though Manager holds every admin-area permission.
        $manager = $this->userWith('Manager');

        $this->actingAs($manager)->get('/admin')->assertForbidden();
        $this->actingAs($manager)->get('/admin/tariffs')->assertForbidden();
        $this->actingAs($manager)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($manager)->get('/admin/tickets')->assertForbidden();
    }

    public function test_manager_can_manage_tariffs_but_not_users_or_settings_in_menaxhimi(): void
    {
        $manager = $this->userWith('Manager');

        $this->actingAs($manager)->get('/menaxhimi/tariffs')->assertOk();
        $this->actingAs($manager)->get('/menaxhimi/vehicle-types')->assertOk();
        $this->actingAs($manager)->get('/menaxhimi/users')->assertForbidden();
        $this->actingAs($manager)->get('/menaxhimi/roles')->assertForbidden();
        $this->actingAs($manager)->get('/menaxhimi/manage-settings')->assertForbidden();
    }

    public function test_manager_can_view_the_audit_log_in_menaxhimi(): void
    {
        $this->actingAs($this->userWith('Manager'))->get('/menaxhimi/audit-logs')->assertOk();
    }

    public function test_manager_can_edit_tickets_in_menaxhimi(): void
    {
        $this->actingAs($this->userWith('Manager'))->get('/menaxhimi/tickets')->assertOk();
    }

    public function test_manager_can_open_the_menaxhimi_root_even_without_view_statistics(): void
    {
        // Manager holds view_statistics by default, so give this one every permission except
        // that one to prove the panel root doesn't 404/403 just because one specific page would.
        $manager = $this->userWith('Manager');
        $manager->roles()->first()->revokePermissionTo('view_statistics');

        $this->actingAs($manager)->get('/menaxhimi')->assertOk();
    }

    public function test_operator_cannot_reach_configuration_pages_in_either_panel(): void
    {
        $operator = $this->userWith('Operator');

        foreach (['/admin', '/menaxhimi'] as $base) {
            $this->actingAs($operator)->get($base.'/tariffs')->assertForbidden();
            $this->actingAs($operator)->get($base.'/vehicle-types')->assertForbidden();
            $this->actingAs($operator)->get($base.'/audit-logs')->assertForbidden();
            $this->actingAs($operator)->get($base.'/tickets')->assertForbidden();
        }
    }

    public function test_a_direct_permission_alone_does_not_open_admin(): void
    {
        // /admin is role-based (Admin only) and nothing — not even holding every admin-area
        // permission directly — opens it for anyone else. Menaxhimi is the permission-gated one.
        $user = User::factory()->create();
        $user->givePermissionTo('view_audit_log');

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_a_direct_permission_does_open_the_corresponding_menaxhimi_page(): void
    {
        // Unlike /admin, menaxhimi is permission-based by design (see Permissions::adminAreaPermissions()
        // and User::canAccessPanel()) — holding view_audit_log is what it takes, role or not. In
        // practice the Users form never lets this be granted individually (see
        // individuallyGrantablePermissions()); this proves the underlying check itself is correct
        // regardless of how the permission got there.
        $user = User::factory()->create();
        $user->givePermissionTo('view_audit_log');

        $this->actingAs($user)->get('/menaxhimi')->assertOk();
        $this->actingAs($user)->get('/menaxhimi/audit-logs')->assertOk();
        $this->actingAs($user)->get('/menaxhimi/tariffs')->assertForbidden();
    }

    public function test_an_operator_with_no_admin_area_permission_cannot_open_menaxhimi(): void
    {
        $operator = $this->userWith('Operator');

        $this->actingAs($operator)->get('/menaxhimi')->assertForbidden();
    }

    public function test_settings_page_is_admin_only(): void
    {
        $this->assertFalse($this->actingAsAndCheck($this->userWith('Manager'), fn () => ManageSettings::canAccess()));
        $this->assertTrue($this->actingAsAndCheck($this->userWith('Admin'), fn () => ManageSettings::canAccess()));
    }

    public function test_admin_saving_settings_updates_the_row_and_writes_an_audit_entry(): void
    {
        $admin = $this->userWith('Admin');
        Setting::current()->update(['parking_name' => 'Parkimi Qendër']);

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
        $this->assertSame('Parkimi Qendër', $entry->old_values['settings']['parking_name'] ?? null);
        $this->assertSame('Renamed Parking', $entry->new_values['settings']['parking_name'] ?? null);
    }

    public function test_admin_can_turn_off_shift_rules_from_settings(): void
    {
        $admin = $this->userWith('Admin');
        $this->assertTrue(Setting::current()->enforce_work_shift_hours);
        $this->assertTrue(Setting::current()->require_cash_declaration);

        $this->actingAs($admin);

        Livewire::test(ManageSettings::class)
            ->set('data.enforce_work_shift_hours', false)
            ->set('data.require_cash_declaration', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Setting::current()->enforce_work_shift_hours);
        $this->assertFalse(Setting::current()->require_cash_declaration);
    }

    public function test_the_extra_permissions_picker_only_offers_whats_not_already_on_every_operator(): void
    {
        // issue_ticket and checkout are excluded on purpose: every Operator already has both
        // from the role, so granting either individually is always a no-op.
        $this->assertSame(
            ['adjust_price', 'adjust_own_ticket_price', 'void_ticket', 'manage_own_tickets'],
            \App\Support\Permissions::individuallyGrantablePermissions(),
        );
    }

    public function test_issue_ticket_and_checkout_cannot_be_granted_individually_even_by_a_crafted_request(): void
    {
        $admin = $this->userWith('Admin');
        $operator = $this->userWith('Operator');

        $issueTicket = \Spatie\Permission\Models\Permission::where('name', 'issue_ticket')->first();
        $checkout = \Spatie\Permission\Models\Permission::where('name', 'checkout')->first();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\UserResource\Pages\EditUser::class, ['record' => $operator->getRouteKey()])
            ->fillForm(['permissions' => [$issueTicket->id, $checkout->id]])
            ->call('save');

        // Harmless either way (the Operator role already grants both), but they must not end up
        // as direct grants — that's exactly the confusing, "does nothing" state this guards against.
        $this->assertEmpty($operator->fresh()->getDirectPermissions()->pluck('name'));
    }

    public function test_an_admin_cannot_grant_an_admin_only_permission_through_the_users_form(): void
    {
        $admin = $this->userWith('Admin');
        $operator = $this->userWith('Operator');

        // A crafted request setting the permissions relationship straight to an admin-only one.
        // The form's own query restricts the relationship's option set, but Livewire state can
        // still be tampered with client-side, so this must fail server-side too.
        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\UserResource\Pages\EditUser::class, ['record' => $operator->getRouteKey()])
            ->fillForm(['permissions' => [\Spatie\Permission\Models\Permission::where('name', 'manage_users')->first()?->id]])
            ->call('save');

        $this->assertFalse($operator->fresh()->can('manage_users'), 'manage_users must not end up directly granted');
        $this->actingAs($operator->fresh())->get('/admin')->assertForbidden();
    }

    public function test_the_menaxhimi_link_shows_on_operator_screens_for_a_manager(): void
    {
        $this->actingAs($this->userWith('Manager'))
            ->get('/operator/shift')
            ->assertOk()
            ->assertSee('Menaxhimi');
    }

    public function test_menaxhimi_links_directly_to_hyrja_and_arketimi_from_its_own_topbar(): void
    {
        // The actual operator tabs, not just a single "go back" link — Manager has both
        // issue_ticket and checkout by default, so both show up with one click each.
        $this->actingAs($this->userWith('Manager'))
            ->get('/menaxhimi')
            ->assertOk()
            ->assertSee('Hyrja')
            ->assertSee('Arkëtimi')
            ->assertSee('Biletë e humbur')
            ->assertSee(route('operator.entry'), false)
            ->assertSee(route('operator.checkout'), false)
            ->assertSee(route('operator.lost'), false)
            ->assertSee(route('operator.shift'), false);
    }

    public function test_menaxhimi_topbar_only_shows_the_tabs_that_permission_allows(): void
    {
        // A Manager-role user, but with checkout (and so Arkëtimi/Biletë e humbur) revoked
        // directly, same as any crafted role configuration — the topbar must reflect that.
        $manager = $this->userWith('Manager');
        $manager->roles()->first()->revokePermissionTo('checkout');

        $this->actingAs($manager)
            ->get('/menaxhimi')
            ->assertOk()
            ->assertSee('Hyrja')
            ->assertDontSee('Arkëtimi')
            ->assertDontSee('Biletë e humbur');
    }

    public function test_the_menaxhimi_link_is_hidden_for_an_operator_with_no_admin_area_permission(): void
    {
        $this->actingAs($this->userWith('Operator'))
            ->get('/operator/shift')
            ->assertOk()
            ->assertDontSee('Menaxhimi');
    }

    public function test_signing_in_through_the_operator_login_is_enough_to_open_menaxhimi(): void
    {
        // The whole point: one login (the operator one), no separate Filament login screen for
        // Menaxhimi. Signs in via the real /operator/login flow, not actingAs, to prove the
        // session it creates is the one Menaxhimi's auth middleware accepts.
        $manager = $this->userWith('Manager'); // factory default password is 'password'

        $this->post('/operator/login', ['email' => $manager->email, 'password' => 'password'])
            ->assertRedirect(route('operator.shift'));

        $this->get('/menaxhimi')->assertOk();
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
