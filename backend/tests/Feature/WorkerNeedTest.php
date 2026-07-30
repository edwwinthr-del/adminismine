<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkerNeed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerNeedTest extends TestCase
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

    public function test_need_is_filed_open_with_normal_priority(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->postJson('/api/worker-needs', [
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'need_type' => 'equipment',
            'description' => 'New safety boots, size 44',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'normal');
    }

    public function test_canonical_values_are_enforced(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->postJson('/api/worker-needs', [
            'employee_id' => $employee->id,
            'date' => '2026-07-20',
            'need_type' => 'oprema', // translated label, not a canonical value
            'description' => 'Boots',
        ])->assertStatus(422)->assertJsonValidationErrors('need_type');
    }

    public function test_office_assigns_and_resolves_a_need(): void
    {
        $office = $this->actingAsAdmin();
        $need = WorkerNeed::factory()->create();

        $this->putJson("/api/worker-needs/{$need->id}", [
            'status' => 'in_review',
            'assigned_user_id' => $office->id,
            'priority' => 'urgent',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.assigned_user.id', $office->id);

        $this->assertNull($need->fresh()->resolved_at);

        $this->putJson("/api/worker-needs/{$need->id}", ['status' => 'resolved'])->assertOk();
        $this->assertNotNull($need->fresh()->resolved_at);

        // Reopening clears the resolution stamp.
        $this->putJson("/api/worker-needs/{$need->id}", ['status' => 'open'])->assertOk();
        $this->assertNull($need->fresh()->resolved_at);
    }

    public function test_index_filters_and_orders_urgent_first(): void
    {
        $this->actingAsAdmin();

        $normal = WorkerNeed::factory()->create(['priority' => 'normal', 'date' => '2026-07-20']);
        $urgent = WorkerNeed::factory()->create(['priority' => 'urgent', 'date' => '2026-07-19']);
        $resolved = WorkerNeed::factory()->create(['status' => 'resolved']);

        $ids = collect($this->getJson('/api/worker-needs?open_only=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$urgent->id, $normal->id], $ids);
        $this->assertNotContains($resolved->id, $ids);

        $ids = collect($this->getJson('/api/worker-needs?status=resolved')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$resolved->id], $ids);
    }

    public function test_worker_needs_require_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/worker-needs')->assertStatus(403);
    }

    // ---- the archive ----------------------------------------------------

    public function test_the_archive_holds_settled_needs_and_the_active_view_holds_the_rest(): void
    {
        $this->actingAsAdmin();

        $open = WorkerNeed::factory()->create(['status' => 'open']);
        $inReview = WorkerNeed::factory()->create(['status' => 'in_review']);
        $resolved = WorkerNeed::factory()->create(['status' => 'resolved', 'resolved_at' => now()]);
        $rejected = WorkerNeed::factory()->create(['status' => 'rejected', 'resolved_at' => now()]);

        $archive = collect($this->getJson('/api/worker-needs?view=archive')->assertOk()->json('data'))
            ->pluck('id');
        $active = collect($this->getJson('/api/worker-needs?view=active')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertEqualsCanonicalizing([$resolved->id, $rejected->id], $archive->all());
        $this->assertEqualsCanonicalizing([$open->id, $inReview->id], $active->all());
    }

    public function test_the_archive_can_be_searched_filtered_and_sorted(): void
    {
        $this->actingAsAdmin();

        $boots = WorkerNeed::factory()->create([
            'status' => 'resolved',
            'description' => 'Needs new safety BOOTS',
            'need_type' => 'equipment',
            'resolved_at' => now()->subDays(3),
        ]);
        $permit = WorkerNeed::factory()->create([
            'status' => 'rejected',
            'description' => 'Work permit renewal',
            'need_type' => 'document',
            'resolved_at' => now()->subDay(),
        ]);

        // Search is case-insensitive on both drivers.
        $found = $this->getJson('/api/worker-needs?view=archive&search=boots')->assertOk()->json('data');
        $this->assertCount(1, $found);
        $this->assertSame($boots->id, $found[0]['id']);

        $filtered = $this->getJson('/api/worker-needs?view=archive&need_type=document')->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame($permit->id, $filtered[0]['id']);

        // Default order: most recently settled first.
        $sorted = collect($this->getJson('/api/worker-needs?view=archive')->json('data'))->pluck('id');
        $this->assertSame([$permit->id, $boots->id], $sorted->all());

        $oldestFirst = collect(
            $this->getJson('/api/worker-needs?view=archive&sort=resolved_at&direction=asc')->json('data'),
        )->pluck('id');
        $this->assertSame([$boots->id, $permit->id], $oldestFirst->all());
    }

    public function test_an_unknown_sort_column_is_refused_rather_than_passed_to_sql(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/worker-needs?sort=id);DROP')->assertStatus(422);
    }

    public function test_resolving_stamps_the_time_and_reopening_clears_it(): void
    {
        $this->actingAsAdmin();
        $need = WorkerNeed::factory()->create(['status' => 'open']);

        $this->putJson("/api/worker-needs/{$need->id}", ['status' => 'resolved'])->assertOk();
        $this->assertNotNull($need->fresh()->resolved_at);

        $this->putJson("/api/worker-needs/{$need->id}", ['status' => 'open'])->assertOk();
        $this->assertNull($need->fresh()->resolved_at);
    }
}
