<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Master;
use App\Models\User;
use App\Models\Worksite;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorksiteMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_create_worksite_and_assign_employees(): void
    {
        $this->actingAsAdmin();

        $worksiteId = $this->postJson('/api/worksites', [
            'name' => 'Mine Selim',
            'location' => 'Niksic',
        ])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');

        $first = Employee::factory()->create();
        $second = Employee::factory()->create();

        $this->putJson("/api/worksites/{$worksiteId}/employees", [
            'employees' => [
                ['employee_id' => $first->id, 'assigned_from' => '2026-07-01'],
                ['employee_id' => $second->id],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.employees');

        // Syncing again with one worker replaces the roster.
        $this->putJson("/api/worksites/{$worksiteId}/employees", [
            'employees' => [['employee_id' => $second->id]],
        ])->assertOk()->assertJsonCount(1, 'data.employees');

        $this->assertDatabaseCount('radnik_gradiliste', 1);
    }

    public function test_worksite_with_attendance_is_deactivated_not_deleted(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->create();

        $this->deleteJson("/api/worksites/{$record->worksite_id}")->assertOk();

        $this->assertDatabaseHas('gradilista', ['id' => $record->worksite_id, 'is_active' => false]);
    }

    public function test_unused_worksite_is_deleted(): void
    {
        $this->actingAsAdmin();
        $worksite = Worksite::factory()->create();

        $this->deleteJson("/api/worksites/{$worksite->id}")->assertOk();

        $this->assertDatabaseMissing('gradilista', ['id' => $worksite->id]);
    }

    public function test_master_is_created_with_worksites_and_one_record_per_employee(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create(['first_name' => 'Selim', 'last_name' => 'Kaya']);
        $login = User::factory()->create();
        $siteA = Worksite::factory()->create();
        $siteB = Worksite::factory()->create();

        $this->postJson('/api/masters', [
            'employee_id' => $employee->id,
            'user_id' => $login->id,
            'worksite_ids' => [$siteA->id, $siteB->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.employee.full_name', 'Selim Kaya')
            ->assertJsonCount(2, 'data.worksites');

        $this->postJson('/api/masters', ['employee_id' => $employee->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_master_worksites_can_be_replaced(): void
    {
        $this->actingAsAdmin();

        $master = Master::factory()->create();
        $siteA = Worksite::factory()->create();
        $siteB = Worksite::factory()->create();
        $master->worksites()->sync([$siteA->id]);

        $this->putJson("/api/masters/{$master->id}", ['worksite_ids' => [$siteB->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.worksites')
            ->assertJsonPath('data.worksites.0.id', $siteB->id);
    }

    public function test_worksites_and_masters_require_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/worksites')->assertStatus(403);
        $this->getJson('/api/masters')->assertStatus(403);
    }
}
