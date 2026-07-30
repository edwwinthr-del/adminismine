<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProductionRecord;
use App\Models\User;
use App\Models\Worksite;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionRecordTest extends TestCase
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

    /** An engineer may file production but not approve it. */
    private function actingAsEngineer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('mining_production.submit');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_daily_entry_defaults_to_bauxite_tons_and_derives_its_month(): void
    {
        $this->actingAsEngineer();
        $worksite = Worksite::factory()->create();
        $engineer = Employee::factory()->create();

        $this->postJson('/api/production', [
            'period_type' => 'daily',
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'engineer_id' => $engineer->id,
            'quantity' => 412.5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.material_type', 'bauxite_ore')
            ->assertJsonPath('data.unit', 'tons')
            ->assertJsonPath('data.period_month', '2026-07-01')
            ->assertJsonPath('data.approval_status', 'draft')
            ->assertJsonPath('data.quantity', 412.5);
    }

    public function test_monthly_entry_accepts_a_month_and_keeps_no_day(): void
    {
        $this->actingAsEngineer();
        $worksite = Worksite::factory()->create();

        $this->postJson('/api/production', [
            'period_type' => 'monthly',
            'period_month' => '2026-07',
            'worksite_id' => $worksite->id,
            'quantity' => 9800,
        ])
            ->assertCreated()
            ->assertJsonPath('data.period_month', '2026-07-01')
            ->assertJsonPath('data.date', null);
    }

    public function test_duplicate_day_and_duplicate_month_are_rejected(): void
    {
        $this->actingAsEngineer();
        $worksite = Worksite::factory()->create();

        $daily = [
            'period_type' => 'daily',
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'quantity' => 100,
        ];
        $this->postJson('/api/production', $daily)->assertCreated();
        $this->postJson('/api/production', $daily)->assertStatus(422)->assertJsonValidationErrors('date');

        // A different material on the same day is still allowed.
        $this->postJson('/api/production', $daily + ['material_type' => 'overburden'])->assertCreated();

        $monthly = [
            'period_type' => 'monthly',
            'period_month' => '2026-07',
            'worksite_id' => $worksite->id,
            'quantity' => 5000,
        ];
        $this->postJson('/api/production', $monthly)->assertCreated();
        $this->postJson('/api/production', $monthly)->assertStatus(422)->assertJsonValidationErrors('period_month');
    }

    public function test_material_and_unit_must_be_canonical_values(): void
    {
        $this->actingAsEngineer();
        $worksite = Worksite::factory()->create();

        $this->postJson('/api/production', [
            'period_type' => 'daily',
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'quantity' => 100,
            'material_type' => 'boksit', // translated label, not canonical
        ])->assertStatus(422)->assertJsonValidationErrors('material_type');

        $this->postJson('/api/production', [
            'period_type' => 'daily',
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'quantity' => 100,
            'unit' => 'tona',
        ])->assertStatus(422)->assertJsonValidationErrors('unit');
    }

    public function test_engineer_cannot_approve_but_manager_can(): void
    {
        $this->actingAsEngineer();
        $record = ProductionRecord::factory()->create();

        $this->postJson("/api/production/{$record->id}/approve")->assertStatus(403);

        $manager = $this->actingAsAdmin();
        $this->postJson("/api/production/{$record->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $record->refresh();
        $this->assertSame($manager->id, $record->approved_by);
        $this->assertNotNull($record->approved_at);
    }

    public function test_approved_record_is_locked_for_the_engineer(): void
    {
        $record = ProductionRecord::factory()->approved()->create(['quantity' => 500]);

        $this->actingAsEngineer();
        $this->putJson("/api/production/{$record->id}", ['quantity' => 600])->assertStatus(422);
        $this->deleteJson("/api/production/{$record->id}")->assertStatus(422);
        $this->assertEqualsWithDelta(500.0, (float) $record->fresh()->quantity, 0.001);

        // A manager may still correct it.
        $this->actingAsAdmin();
        $this->putJson("/api/production/{$record->id}", ['quantity' => 600])->assertOk();
        $this->assertEqualsWithDelta(600.0, (float) $record->fresh()->quantity, 0.001);
    }

    public function test_draft_record_is_editable_before_approval(): void
    {
        $this->actingAsEngineer();
        $record = ProductionRecord::factory()->create(['quantity' => 100]);

        $this->putJson("/api/production/{$record->id}", ['quantity' => 150, 'quality_grade' => 'A'])
            ->assertOk()
            ->assertJsonPath('data.quantity', 150)
            ->assertJsonPath('data.quality_grade', 'A');
    }

    public function test_rejection_requires_a_reason(): void
    {
        $this->actingAsAdmin();
        $record = ProductionRecord::factory()->approved()->create();

        $this->postJson("/api/production/{$record->id}/reject")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/production/{$record->id}/reject", ['reason' => 'Scale reading disputed'])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Scale reading disputed');

        $this->assertNull($record->fresh()->approved_at);
    }

    public function test_totals_cover_daily_month_and_year_to_date(): void
    {
        $this->actingAsAdmin();
        $mine = Worksite::factory()->create(['name' => 'Mine A']);
        $other = Worksite::factory()->create(['name' => 'Mine B']);

        ProductionRecord::factory()->create(['worksite_id' => $mine->id, 'date' => '2026-07-15', 'period_month' => '2026-07-01', 'quantity' => 400]);
        ProductionRecord::factory()->create(['worksite_id' => $mine->id, 'date' => '2026-07-16', 'period_month' => '2026-07-01', 'quantity' => 350]);
        ProductionRecord::factory()->create(['worksite_id' => $other->id, 'date' => '2026-07-16', 'period_month' => '2026-07-01', 'quantity' => 200]);
        ProductionRecord::factory()->create(['worksite_id' => $mine->id, 'date' => '2026-06-10', 'period_month' => '2026-06-01', 'quantity' => 1000]);
        ProductionRecord::factory()->create(['worksite_id' => $mine->id, 'date' => '2025-07-10', 'period_month' => '2025-07-01', 'quantity' => 5000]);

        $response = $this->getJson('/api/production/totals?month=2026-07')->assertOk();

        $this->assertEqualsWithDelta(950, $response->json('data.month_total'), 0.001);
        $this->assertEqualsWithDelta(1950, $response->json('data.year_to_date_total'), 0.001); // 2026 only
        $this->assertCount(2, $response->json('data.daily'));
        $this->assertSame('2026-07-15', $response->json('data.daily.0.date'));
        $this->assertEqualsWithDelta(550, $response->json('data.daily.1.quantity'), 0.001);
        $this->assertSame('Mine A', $response->json('data.by_worksite.0.name'));
        $this->assertEqualsWithDelta(750, $response->json('data.by_worksite.0.quantity'), 0.001);
        $this->assertSame(3, $response->json('data.pending_approval'));

        // Filtering by worksite narrows both the month and the year figure.
        $filtered = $this->getJson("/api/production/totals?month=2026-07&worksite_id={$mine->id}")->assertOk();
        $this->assertEqualsWithDelta(750, $filtered->json('data.month_total'), 0.001);
        $this->assertEqualsWithDelta(1750, $filtered->json('data.year_to_date_total'), 0.001);
    }

    public function test_totals_can_count_approved_records_only(): void
    {
        $this->actingAsAdmin();

        ProductionRecord::factory()->approved()->create(['date' => '2026-07-15', 'period_month' => '2026-07-01', 'quantity' => 300]);
        ProductionRecord::factory()->create(['date' => '2026-07-16', 'period_month' => '2026-07-01', 'quantity' => 200]);

        $this->getJson('/api/production/totals?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.month_total', 500);

        $this->getJson('/api/production/totals?month=2026-07&approved_only=1')
            ->assertOk()
            ->assertJsonPath('data.month_total', 300);
    }

    public function test_month_total_adds_monthly_entries_to_daily_rows(): void
    {
        $this->actingAsAdmin();
        $worksite = Worksite::factory()->create();

        ProductionRecord::factory()->create(['worksite_id' => $worksite->id, 'date' => '2026-07-15', 'period_month' => '2026-07-01', 'quantity' => 400]);
        ProductionRecord::factory()->monthly()->create(['worksite_id' => $worksite->id, 'period_month' => '2026-07-01', 'quantity' => 600, 'material_type' => 'limestone']);

        $response = $this->getJson('/api/production/totals?month=2026-07')->assertOk();

        $this->assertEqualsWithDelta(400, $response->json('data.daily_total'), 0.001);
        $this->assertEqualsWithDelta(600, $response->json('data.monthly_entries_total'), 0.001);
        $this->assertEqualsWithDelta(1000, $response->json('data.month_total'), 0.001);
    }

    public function test_index_filters_by_month_worksite_and_material(): void
    {
        $this->actingAsAdmin();
        $mine = Worksite::factory()->create();

        $july = ProductionRecord::factory()->create(['worksite_id' => $mine->id, 'date' => '2026-07-15', 'period_month' => '2026-07-01']);
        ProductionRecord::factory()->create(['date' => '2026-06-15', 'period_month' => '2026-06-01']);
        $limestone = ProductionRecord::factory()->create([
            'worksite_id' => $mine->id, 'date' => '2026-07-17', 'period_month' => '2026-07-01', 'material_type' => 'limestone',
        ]);

        $ids = collect($this->getJson('/api/production?month=2026-07')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$july->id, $limestone->id], $ids);

        $ids = collect($this->getJson('/api/production?month=2026-07&material_type=limestone')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$limestone->id], $ids);

        $ids = collect($this->getJson("/api/production?worksite_id={$mine->id}&period_type=daily")->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$july->id, $limestone->id], $ids);
    }

    public function test_production_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/production')->assertStatus(403);
        $this->getJson('/api/production/totals')->assertStatus(403);
    }
}
