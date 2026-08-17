<?php

namespace Tests\Feature;

use App\Models\ActivityLog as Activity;
use App\Models\Client;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Suppliers and clients had no test at all.
 *
 * They are two of the smallest controllers in the app, which is presumably why
 * they were skipped â€” but every payable points at a supplier and every
 * receivable at a client, so these are the rows money is routed *to*. Creating
 * one was also the last write in the app that left no audit trail, which is the
 * combination worth covering: an untested endpoint that creates an unrecorded
 * payee.
 */
class SupplierClientTest extends TestCase
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

    public function test_creating_a_supplier_is_recorded_against_its_author(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/suppliers', ['name' => 'Acme DOO'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme DOO');

        $supplier = Supplier::sole();
        $activity = Activity::where('description', 'supplier.created')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($supplier->id, $activity->subject_id);
        $this->assertSame('Acme DOO', $activity->properties['name']);
    }

    public function test_creating_a_client_is_recorded_against_its_author(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/clients', ['name' => 'Uniprom'])->assertCreated();

        $activity = Activity::where('description', 'client.created')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(Client::sole()->id, $activity->subject_id);
    }

    /**
     * Search is the only way these lists are navigated once they are long.
     *
     * Deliberately an ASCII name: SQLite's `lower()` folds ASCII only, so a
     * case-varied *Serbian* name cannot match under the test driver even though
     * it does in production on Postgres. See
     * DriverPortabilityTest::test_case_folding_of_non_ascii_differs_by_driver,
     * which pins that divergence rather than hiding it.
     */
    public function test_suppliers_can_be_searched_case_insensitively(): void
    {
        $this->actingAsAdmin();

        Supplier::factory()->create(['name' => 'ACME Trans']);
        Supplier::factory()->create(['name' => 'Niksic Rudnik']);

        $names = collect($this->getJson('/api/suppliers?search=acme')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertCount(1, $names);
        $this->assertSame('ACME Trans', $names->first());
    }

    public function test_the_active_only_filter_hides_retired_suppliers(): void
    {
        $this->actingAsAdmin();

        Supplier::factory()->create(['name' => 'Current', 'is_active' => true]);
        Supplier::factory()->create(['name' => 'Retired', 'is_active' => false]);

        $all = $this->getJson('/api/suppliers')->assertOk()->json('data');
        $active = $this->getJson('/api/suppliers?active_only=1')->assertOk()->json('data');

        $this->assertCount(2, $all);
        $this->assertCount(1, $active);
        $this->assertSame('Current', $active[0]['name']);
    }

    /** Suppliers sit behind payables, clients behind receivables â€” never each other's. */
    public function test_each_list_carries_the_permission_of_the_module_behind_it(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('payables.view');
        Sanctum::actingAs($user);

        $this->getJson('/api/suppliers')->assertOk();
        $this->getJson('/api/clients')->assertForbidden();

        // Viewing payables is not permission to create a payee.
        $this->postJson('/api/suppliers', ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(0, Supplier::count());
    }
}
