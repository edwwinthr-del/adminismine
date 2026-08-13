<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
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

    /**
     * Asserted through a real bearer token, not Sanctum::actingAs.
     *
     * `actingAs` installs a transient fake token, so the old version of this
     * test — which asserted only that logout returned 200 — passed whether or
     * not anything was revoked. It would have survived deleting the revocation
     * entirely, while its name promised the opposite.
     */
    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('laptop')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        // The guard caches the user it resolved above; a real request would
        // resolve it from scratch.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertStatus(401);
    }

    /** Signing out of one device must not sign the person out of the others. */
    public function test_logout_leaves_other_sessions_alone(): void
    {
        $user = User::factory()->create();
        $laptop = $user->createToken('laptop')->plainTextToken;
        $phone = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$laptop}")->postJson('/api/logout')->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$phone}")->getJson('/api/me')->assertOk();
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

    /**
     * Laravel 11+ leaves the api group unthrottled unless it is asked for, so
     * this endpoint accepted guesses as fast as they could be sent.
     */
    public function test_login_attempts_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'target@example.com']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'target@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'target@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // The lockout is per email + address, so the right password is refused
        // too until the window passes — that is the point of it.
        $this->postJson('/api/login', [
            'email' => 'target@example.com',
            'password' => 'password',
        ])->assertStatus(429);
    }

    /**
     * The trail recorded every financial edit and not one sign-in, so a stolen
     * or hijacked account left no first footprint.
     */
    public function test_authentication_events_reach_the_audit_log(): void
    {
        $user = User::factory()->create(['email' => 'audited@example.com']);

        $this->postJson('/api/login', ['email' => 'audited@example.com', 'password' => 'nope'])
            ->assertStatus(422);
        $this->assertDatabaseHas('activity_log', ['description' => 'auth.login_failed']);

        // A guess at somebody's address must not write rows that read as that
        // person's own actions.
        $failure = Activity::where('description', 'auth.login_failed')->latest('id')->firstOrFail();
        $this->assertNull($failure->causer_id);
        $this->assertSame('audited@example.com', $failure->properties['email']);

        $token = $this->postJson('/api/login', ['email' => 'audited@example.com', 'password' => 'password'])
            ->assertOk()->json('token');

        $login = Activity::where('description', 'auth.login')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $login->causer_id);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('activity_log', ['description' => 'auth.logout', 'causer_id' => $user->id]);
    }

    /** An unknown email must cost the same time as a wrong password. */
    public function test_an_unknown_email_is_not_distinguishable_from_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $unknown = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'guess']);
        $wrong = $this->postJson('/api/login', ['email' => 'known@example.com', 'password' => 'guess']);

        $unknown->assertStatus(422);
        $wrong->assertStatus(422);
        $this->assertSame($unknown->json('message'), $wrong->json('message'));
        $this->assertSame($unknown->json('errors'), $wrong->json('errors'));
    }
}
