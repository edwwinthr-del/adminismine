<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Mine;
use App\Models\ProductionRecord;
use App\Models\Project;
use App\Models\User;
use App\Models\Worksite;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MineProjectTest extends TestCase
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

    public function test_mines_and_projects_are_created_and_listed(): void
    {
        $this->actingAsAdmin();

        $mineId = $this->postJson('/api/mines', [
            'name' => 'Zagrad',
            'code' => 'ZG-1',
            'location' => 'Niksic',
            'material_type' => 'bauxite_ore',
        ])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');

        $client = Client::factory()->create(['name' => 'Uniprom']);

        $projectId = $this->postJson('/api/projects', [
            'name' => 'Uniprom 2026',
            'client_id' => $client->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/mines')->assertOk()->assertJsonPath('data.0.name', 'Zagrad');
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('data.0.client.name', 'Uniprom');

        // A worksite carries the structure; the records hanging off it inherit it.
        $this->postJson('/api/worksites', [
            'name' => 'Selim team site',
            'mine_id' => $mineId,
            'project_id' => $projectId,
        ])->assertCreated()
            ->assertJsonPath('data.mine.name', 'Zagrad')
            ->assertJsonPath('data.project.name', 'Uniprom 2026');
    }

    public function test_project_end_date_cannot_precede_its_start(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/projects', [
            'name' => 'Backwards',
            'start_date' => '2026-06-01',
            'end_date' => '2026-05-01',
        ])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_attendance_production_and_machines_filter_by_mine_and_project(): void
    {
        $this->actingAsAdmin();

        $mine = Mine::factory()->create();
        $project = Project::factory()->create();

        $onStructure = Worksite::factory()->create(['mine_id' => $mine->id, 'project_id' => $project->id]);
        $elsewhere = Worksite::factory()->create();

        AttendanceRecord::factory()->create(['worksite_id' => $onStructure->id]);
        AttendanceRecord::factory()->create(['worksite_id' => $elsewhere->id]);
        ProductionRecord::factory()->create(['worksite_id' => $onStructure->id]);
        ProductionRecord::factory()->create(['worksite_id' => $elsewhere->id]);
        Machine::factory()->create(['worksite_id' => $onStructure->id]);
        Machine::factory()->create(['worksite_id' => $elsewhere->id]);

        $this->getJson("/api/attendance?mine_id={$mine->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/attendance?project_id={$project->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/production?mine_id={$mine->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/machines?project_id={$project->id}")->assertOk()->assertJsonCount(1, 'data');

        // And the reports that stand behind those screens agree.
        $this->getJson("/api/reports/attendance_report?mine_id={$mine->id}")
            ->assertOk()->assertJsonPath('data.row_count', 1);
        $this->getJson("/api/reports/machine_equipment_register?project_id={$project->id}")
            ->assertOk()->assertJsonPath('data.row_count', 1);
    }

    public function test_worksites_are_filtered_and_searched_by_structure(): void
    {
        $this->actingAsAdmin();

        $mine = Mine::factory()->create(['name' => 'Biocki Stan']);
        Worksite::factory()->create(['name' => 'Site A', 'mine_id' => $mine->id]);
        Worksite::factory()->create(['name' => 'Site B']);

        $this->getJson("/api/worksites?mine_id={$mine->id}")->assertOk()->assertJsonCount(1, 'data');
        // Searching a site by the name of its mine finds it.
        $this->getJson('/api/worksites?search=Biocki')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_mine_with_worksites_is_deactivated_not_deleted(): void
    {
        $this->actingAsAdmin();

        $mine = Mine::factory()->create();
        Worksite::factory()->create(['mine_id' => $mine->id]);

        $this->deleteJson("/api/mines/{$mine->id}")->assertOk();
        $this->assertDatabaseHas('mines', ['id' => $mine->id, 'is_active' => false]);

        // An unused one is removed outright.
        $unused = Mine::factory()->create();
        $this->deleteJson("/api/mines/{$unused->id}")->assertOk();
        $this->assertDatabaseMissing('mines', ['id' => $unused->id]);
    }

    public function test_project_with_worksites_is_deactivated_not_deleted(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        Worksite::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/projects/{$project->id}")->assertOk();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'is_active' => false]);
    }

    public function test_deleting_a_project_leaves_its_worksites_in_place(): void
    {
        $this->actingAsAdmin();

        $project = Project::factory()->create();
        $worksite = Worksite::factory()->create(['project_id' => $project->id]);

        // Force the delete the API would refuse, to prove the FK is nullOnDelete:
        // losing a project must never take a worksite (and its attendance) with it.
        $project->delete();

        $this->assertDatabaseHas('worksites', ['id' => $worksite->id, 'project_id' => null]);
    }

    public function test_lookups_expose_mines_and_projects(): void
    {
        $this->actingAsAdmin();

        Mine::factory()->create(['name' => 'Vrsuta']);
        Project::factory()->create(['name' => 'Haul road']);

        $this->getJson('/api/lookups/mines?search=Vrs')->assertOk()->assertJsonPath('data.0.label', 'Vrsuta');
        $this->getJson('/api/lookups/projects?search=Haul')->assertOk()->assertJsonPath('data.0.label', 'Haul road');
    }

    public function test_work_structure_requires_the_worksites_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->getJson('/api/mines')->assertForbidden();
        $this->getJson('/api/projects')->assertForbidden();
        $this->postJson('/api/mines', ['name' => 'Sneaky'])->assertForbidden();
    }
}
