<?php

namespace Tests\Feature;

use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
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

    public function test_a_recorded_action_shows_up_in_the_audit_log(): void
    {
        $user = $this->actingAsAdmin();
        $supplier = Supplier::factory()->create();

        $this->postJson('/api/payables', [
            'supplier_id' => $supplier->id,
            'invoice_date' => '2026-07-01',
            'invoice_number' => 'INV-1',
            'original_amount' => 500,
        ])->assertCreated();

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'payable.created')
            ->assertJsonPath('data.0.subject_label', 'PayableInvoice')
            ->assertJsonPath('data.0.causer.name', $user->name);
    }

    public function test_the_log_filters_by_event_actor_and_record_type(): void
    {
        $user = $this->actingAsAdmin();
        $supplier = Supplier::factory()->create();
        $invoice = PayableInvoice::factory()->create(['supplier_id' => $supplier->id]);

        $this->putJson("/api/payables/{$invoice->id}", ['invoice_number' => 'CORRECTED'])->assertOk();
        $this->postJson('/api/mines', ['name' => 'Zagrad'])->assertCreated();

        $this->getJson('/api/audit-logs?search=mine.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'mine.created');

        $this->getJson('/api/audit-logs?subject_type='.urlencode(PayableInvoice::class))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'payable.updated');

        $this->getJson("/api/audit-logs?causer_id={$user->id}")->assertOk()->assertJsonCount(2, 'data');

        // A different actor's id returns none of this user's rows.
        $other = User::factory()->create();
        $this->getJson("/api/audit-logs?causer_id={$other->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filter_options_list_only_what_is_present(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/mines', ['name' => 'Zagrad'])->assertCreated();

        $this->getJson('/api/audit-logs/filters')
            ->assertOk()
            ->assertJsonPath('data.subject_types.0.label', 'Mine')
            ->assertJsonPath('data.events.0', 'mine.created');
    }

    public function test_the_audit_log_needs_its_own_permission_and_is_read_only(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer'); // has reports.view + payables.view, not audit_logs.view
        Sanctum::actingAs($user);

        $this->getJson('/api/audit-logs')->assertForbidden();

        // There is no write route: an audit trail the app can edit is not one.
        $this->postJson('/api/audit-logs', ['event' => 'made.up'])->assertMethodNotAllowed();
        $this->deleteJson('/api/audit-logs/1')->assertNotFound();
    }
}
