<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_a_token(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'user@example.com']);

        $this->postJson('/api/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_me_returns_the_authenticated_user_with_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles.0', 'Admin');
    }

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/logout')->assertOk();
    }

    /**
     * Changing your own password is the one account action that belongs to the
     * account holder, so it is gated on nothing but being signed in — a Worker
     * with no permission at all can still do it.
     */
    public function test_any_signed_in_user_can_change_their_own_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'a-much-better-one-1',
            'password_confirmation' => 'a-much-better-one-1',
        ])->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-much-better-one-1'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-much-better-one-1',
            'password_confirmation' => 'a-much-better-one-1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        // Unchanged: the old one still signs in.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_a_new_password_must_be_confirmed_and_strong(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'a-much-better-one-1',
            'password_confirmation' => 'something-else-1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
