<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
}
