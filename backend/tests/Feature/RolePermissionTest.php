<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_super_admin_can_create_a_role(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/roles', ['name' => 'Accountant', 'permissions' => ['payables.view']])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Accountant')
            ->assertJsonPath('data.permissions.0', 'payables.view');

        $this->assertTrue(Role::where('name', 'Accountant')->exists());
    }

    public function test_user_without_permission_cannot_create_a_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->postJson('/api/roles', ['name' => 'Hacker'])->assertStatus(403);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $this->actingAsSuperAdmin();

        $superAdminRole = Role::findByName('Super Admin', 'web');
        $this->deleteJson("/api/roles/{$superAdminRole->id}")->assertStatus(403);

        $this->assertTrue(Role::where('name', 'Super Admin')->exists());
    }

    public function test_custom_role_with_assigned_users_cannot_be_deleted(): void
    {
        $this->actingAsSuperAdmin();

        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
        User::factory()->create()->assignRole('Temp');

        $this->deleteJson("/api/roles/{$role->id}")->assertStatus(422);
    }

    public function test_empty_custom_role_can_be_deleted(): void
    {
        $this->actingAsSuperAdmin();
        $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);

        $this->deleteJson("/api/roles/{$role->id}")->assertOk();
        $this->assertFalse(Role::where('name', 'Temp')->exists());
    }

    public function test_role_can_be_cloned_with_its_permissions(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/roles/'.Role::findByName('Viewer', 'web')->id.'/clone', ['name' => 'Viewer Copy'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Viewer Copy')
            ->assertJsonPath('data.permissions', ['payables.view', 'reports.view']);
    }

    public function test_admin_can_sync_roles_for_a_user(): void
    {
        $this->actingAsSuperAdmin();

        $target = User::factory()->create();
        $this->putJson("/api/users/{$target->id}/roles", ['roles' => ['Viewer']])
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'Viewer');

        $this->assertTrue($target->fresh()->hasRole('Viewer'));
    }

    public function test_user_list_is_available_to_user_managers_only(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create(['name' => 'Field Master']);
        $target->assignRole('Worker');

        $names = collect($this->getJson('/api/users')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Field Master'));

        $outsider = User::factory()->create();
        $outsider->assignRole('Worker');
        Sanctum::actingAs($outsider);

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_admin_can_create_a_user_who_can_then_log_in(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', [
            'name' => 'Ayse Yilmaz',
            'email' => 'ayse@adminismine.local',
            'password' => 'first-password-1',
            'locale' => 'tr',
            'roles' => ['Viewer'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'ayse@adminismine.local')
            ->assertJsonPath('data.locale', 'tr')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.roles.0', 'Viewer');

        $this->postJson('/api/login', [
            'email' => 'ayse@adminismine.local',
            'password' => 'first-password-1',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_creating_a_user_never_stores_the_password_in_the_audit_trail(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', [
            'name' => 'Audited',
            'email' => 'audited@adminismine.local',
            'password' => 'first-password-1',
        ])->assertCreated();

        $activity = Activity::where('description', 'user.created')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('first-password-1', json_encode($activity->properties));
    }

    public function test_user_without_permission_cannot_create_a_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->postJson('/api/users', [
            'name' => 'Sneaky',
            'email' => 'sneaky@adminismine.local',
            'password' => 'first-password-1',
        ])->assertStatus(403);

        $this->assertFalse(User::where('email', 'sneaky@adminismine.local')->exists());
    }

    public function test_duplicate_email_is_refused(): void
    {
        $this->actingAsSuperAdmin();
        User::factory()->create(['email' => 'taken@adminismine.local']);

        $this->postJson('/api/users', [
            'name' => 'Second',
            'email' => 'taken@adminismine.local',
            'password' => 'first-password-1',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create(['password' => 'known-password-1']);

        $this->putJson("/api/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'known-password-1'])
            ->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_or_restrict_their_own_account(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->putJson("/api/users/{$admin->id}/roles", ['roles' => []])->assertStatus(422);
        $this->putJson("/api/users/{$admin->id}/extra-permissions", ['permissions' => []])->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->hasRole('Super Admin'));
    }

    public function test_the_last_active_super_admin_cannot_lose_the_role_or_be_deactivated(): void
    {
        // An Admin (has users.manage, is not a Super Admin) acting on the only
        // Super Admin there is.
        $lastSuperAdmin = User::factory()->create();
        $lastSuperAdmin->assignRole('Super Admin');

        $manager = User::factory()->create();
        $manager->assignRole('Admin');
        Sanctum::actingAs($manager);

        $this->putJson("/api/users/{$lastSuperAdmin->id}/roles", ['roles' => ['Viewer']])->assertStatus(422);
        $this->putJson("/api/users/{$lastSuperAdmin->id}", ['is_active' => false])->assertStatus(422);

        $this->assertTrue($lastSuperAdmin->fresh()->hasRole('Super Admin'));
        $this->assertTrue($lastSuperAdmin->fresh()->is_active);

        // With a second holder the same edit goes through.
        $spare = User::factory()->create();
        $spare->assignRole('Super Admin');

        $this->putJson("/api/users/{$lastSuperAdmin->id}/roles", ['roles' => ['Viewer']])->assertOk();
    }

    public function test_password_reset_replaces_the_password_and_revokes_old_tokens(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create(['password' => 'known-password-1']);
        $target->createToken('phone');

        $this->putJson("/api/users/{$target->id}/password", ['password' => 'brand-new-password-2'])->assertOk();

        $this->assertSame(0, $target->tokens()->count());
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'known-password-1'])
            ->assertStatus(422);
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'brand-new-password-2'])
            ->assertOk();
    }

    public function test_deactivation_ends_a_signed_in_session_immediately(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create();
        $target->assignRole('Viewer');
        $target->createToken('laptop');

        $this->putJson("/api/users/{$target->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
    }

    /**
     * A token that outlives the deactivation — the flag flipped straight in the
     * database, say — is refused on the next request, not at the next login.
     * No Sanctum::actingAs here: that would override the bearer token.
     */
    public function test_a_token_issued_before_deactivation_stops_working(): void
    {
        $target = User::factory()->create();
        $target->assignRole('Viewer');
        $token = $target->createToken('laptop')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertOk();

        $target->forceFill(['is_active' => false])->save();

        // The guard caches the user it resolved for the request above; a real
        // request would resolve it again from scratch, so drop the cache.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertStatus(401);
        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_weak_passwords_are_refused(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', [
            'name' => 'Weak',
            'email' => 'weak@adminismine.local',
            'password' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * The roles list itself, which nothing covered before.
     *
     * It used to 500 on every call: counting holders through Spatie's `users`
     * relation resolves the model from `config('auth.defaults.guard')`, and
     * `auth:sanctum` sets that to a guard this app never defines — so the list
     * that the Roles screen and the create-user role picker both read was
     * unreachable, while the POST that creates a role worked. That is the whole
     * "I can't add a new role": roles were being saved into a list that could
     * not be displayed.
     */
    public function test_the_roles_list_loads_with_its_headcount(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $holder = User::factory()->create();
        $holder->assignRole('Viewer');

        $roles = collect($this->getJson('/api/roles')->assertOk()->json('data'));

        $this->assertTrue($roles->contains('name', 'Super Admin'));
        $this->assertSame(1, $roles->firstWhere('name', 'Viewer')['users_count']);
        $this->assertSame(1, $roles->firstWhere('name', 'Super Admin')['users_count']);
        $this->assertSame(0, $roles->firstWhere('name', 'Worker')['users_count']);
        $this->assertTrue($admin->hasRole('Super Admin'));
    }

    public function test_a_created_role_appears_in_the_list_with_its_permissions(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/roles', [
            'name' => 'Payments Officer',
            'permissions' => ['payables.view', 'payables.create'],
        ])->assertCreated();

        $created = collect($this->getJson('/api/roles')->assertOk()->json('data'))
            ->firstWhere('name', 'Payments Officer');

        $this->assertNotNull($created);
        $this->assertEqualsCanonicalizing(['payables.view', 'payables.create'], $created['permissions']);
        $this->assertFalse($created['is_system']);
    }

    public function test_a_role_can_be_edited_and_then_deleted(): void
    {
        $this->actingAsSuperAdmin();

        $roleId = $this->postJson('/api/roles', ['name' => 'Temp', 'permissions' => ['payables.view']])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/roles/{$roleId}", [
            'name' => 'Temp Renamed',
            'permissions' => ['payables.view', 'receivables.manage'],
        ])->assertOk()->assertJsonPath('data.name', 'Temp Renamed');

        $this->deleteJson("/api/roles/{$roleId}")->assertOk();
        $this->assertFalse(Role::where('name', 'Temp Renamed')->exists());
    }

    /**
     * Effective permissions are the role's plus the user's own, and both are
     * settled when the login is created.
     */
    public function test_a_new_user_gets_their_role_plus_any_extra_permissions(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/roles', ['name' => 'Site Clerk', 'permissions' => ['attendance.submit']])
            ->assertCreated();

        $this->postJson('/api/users', [
            'name' => 'Clerk',
            'email' => 'clerk@adminismine.local',
            'password' => 'first-password-1',
            'roles' => ['Site Clerk'],
            'permissions' => ['payables.view'],
        ])->assertCreated()
            ->assertJsonPath('data.roles.0', 'Site Clerk')
            ->assertJsonPath('data.direct_permissions.0', 'payables.view');

        $clerk = User::where('email', 'clerk@adminismine.local')->first();

        // The union, not one or the other.
        $this->assertTrue($clerk->can('attendance.submit'));
        $this->assertTrue($clerk->can('payables.view'));
        $this->assertFalse($clerk->can('payables.approve'));
    }

    /** Enforced by the API, not by which buttons the frontend draws. */
    public function test_extra_permissions_are_enforced_on_the_backend(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', [
            'name' => 'Reader',
            'email' => 'reader@adminismine.local',
            'password' => 'first-password-1',
            'permissions' => ['payables.view'],
        ])->assertCreated();

        Sanctum::actingAs(User::where('email', 'reader@adminismine.local')->first());

        $this->getJson('/api/payables')->assertOk();
        // Granted the view, never the approval that deleting an invoice needs.
        $this->getJson('/api/bank-transactions')->assertStatus(403);
    }

    public function test_super_admin_can_delete_a_user_they_created(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $id = $this->postJson('/api/users', [
            'name' => 'Mistake',
            'email' => 'mistake@adminismine.local',
            'password' => 'first-password-1',
        ])->assertCreated()->json('data.id');

        $this->assertSame($admin->id, User::find($id)->created_by);

        // The password is required every time, and a wrong one changes nothing.
        $this->deleteJson("/api/users/{$id}")->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->deleteJson("/api/users/{$id}", ['current_password' => 'nope'])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $id]);

        $this->deleteJson("/api/users/{$id}", ['current_password' => 'password'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    public function test_a_user_someone_else_created_cannot_be_deleted(): void
    {
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('Super Admin');
        Sanctum::actingAs($otherAdmin);

        $id = $this->postJson('/api/users', [
            'name' => 'Theirs',
            'email' => 'theirs@adminismine.local',
            'password' => 'first-password-1',
        ])->assertCreated()->json('data.id');

        $this->actingAsSuperAdmin();

        $this->deleteJson("/api/users/{$id}", ['current_password' => 'password'])->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $id]);
    }

    public function test_an_admin_who_is_not_super_admin_cannot_delete_a_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin);

        $target = User::factory()->create(['created_by' => $admin->id]);

        $this->deleteJson("/api/users/{$target->id}", ['current_password' => 'password'])->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_you_cannot_delete_your_own_account(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $admin->forceFill(['created_by' => $admin->id])->save();

        $this->deleteJson("/api/users/{$admin->id}", ['current_password' => 'password'])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
