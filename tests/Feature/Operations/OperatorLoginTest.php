<?php

namespace Tests\Feature\Operations;

use App\Models\User;

class OperatorLoginTest extends OperationsTestCase
{
    public function test_operator_can_sign_in_and_reach_the_home_screen(): void
    {
        $operator = User::factory()->create(['email' => 'op@example.test']);
        $operator->assignRole('Operator');

        $this->post('/operator/login', ['email' => 'op@example.test', 'password' => 'password'])
            ->assertRedirect(route('operator.home'));

        $this->assertAuthenticatedAs($operator);
        $this->get('/operator')->assertOk()->assertSee('Arkëtimi');
    }

    public function test_wrong_password_is_refused(): void
    {
        User::factory()->create(['email' => 'op@example.test'])->assignRole('Operator');

        $this->post('/operator/login', ['email' => 'op@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_account_cannot_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'off@example.test', 'is_active' => false]);
        $user->assignRole('Operator');

        $this->post('/operator/login', ['email' => 'off@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_session_is_ended_when_the_account_is_deactivated(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Operator');

        $this->actingAs($user)->get('/operator')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/operator')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guests_are_sent_to_the_operator_login(): void
    {
        $this->get('/operator/entry')->assertRedirect(route('login'));
    }
}
