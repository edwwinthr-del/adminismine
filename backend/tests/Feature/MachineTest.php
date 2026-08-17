<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Machine;
use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Worksite;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MachineTest extends TestCase
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

    public function test_machine_is_created_with_purchase_details_and_links(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::factory()->create(['name' => 'Balkan Machinery']);
        $invoice = PayableInvoice::factory()->create(['supplier_id' => $supplier->id]);
        $transaction = BankTransaction::factory()->create();
        $worksite = Worksite::factory()->create();

        $this->postJson('/api/machines', [
            'machine_type' => 'excavator',
            'brand' => 'Komatsu',
            'model' => 'PC210',
            'serial_number' => 'SN-99',
            'purchase_date' => '2026-03-10',
            'supplier_id' => $supplier->id,
            'purchase_invoice_number' => 'INV-77',
            'purchase_amount' => 125000,
            'payable_invoice_id' => $invoice->id,
            'bank_transaction_id' => $transaction->id,
            'current_location' => 'Mine yard',
            'worksite_id' => $worksite->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Komatsu PC210')
            ->assertJsonPath('data.purchased_from', 'Balkan Machinery')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.payable_invoice_id', $invoice->id)
            ->assertJsonPath('data.bank_transaction_id', $transaction->id);
    }

    public function test_seller_name_is_used_when_there_is_no_supplier_record(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/machines', [
            'machine_type' => 'generator',
            'seller_name' => 'Private seller, Podgorica',
            'purchase_amount' => 4200,
        ])
            ->assertCreated()
            ->assertJsonPath('data.purchased_from', 'Private seller, Podgorica')
            ->assertJsonPath('data.display_name', 'generator');
    }

    public function test_status_must_be_canonical(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/machines', ['machine_type' => 'truck', 'status' => 'prodato'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_machine_can_be_moved_and_marked_sold(): void
    {
        $this->actingAsAdmin();
        $machine = Machine::factory()->create();
        $worksite = Worksite::factory()->create(['name' => 'Quarry North']);

        $this->putJson("/api/machines/{$machine->id}", [
            'worksite_id' => $worksite->id,
            'current_location' => 'Quarry North pit',
            'status' => 'maintenance',
        ])
            ->assertOk()
            ->assertJsonPath('data.worksite.name', 'Quarry North')
            ->assertJsonPath('data.status', 'maintenance');

        $this->putJson("/api/machines/{$machine->id}", ['status' => 'sold'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sold');
    }

    public function test_index_filters_by_status_worksite_and_search(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $active = Machine::factory()->create([
            'brand' => 'Volvo', 'model' => 'A40', 'serial_number' => 'SN-FIND', 'worksite_id' => $worksite->id,
        ]);
        Machine::factory()->sold()->create(['brand' => 'Liebherr']);
        Machine::factory()->inMaintenance()->create(['brand' => 'Caterpillar']);

        $ids = collect($this->getJson('/api/machines?status=active')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$active->id], $ids);

        $ids = collect($this->getJson('/api/machines?in_service=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertCount(2, $ids); // active + maintenance, sold excluded

        $ids = collect($this->getJson('/api/machines?search=SN-FIND')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$active->id], $ids);

        $ids = collect($this->getJson("/api/machines?worksite_id={$worksite->id}")->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$active->id], $ids);
    }

    public function test_register_summarises_counts_and_invested_value(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create(['name' => 'Mine A']);
        Machine::factory()->create(['purchase_amount' => 100000, 'machine_type' => 'excavator', 'worksite_id' => $worksite->id]);
        Machine::factory()->create(['purchase_amount' => 50000, 'machine_type' => 'excavator', 'worksite_id' => $worksite->id]);
        Machine::factory()->sold()->create(['purchase_amount' => 25000, 'machine_type' => 'truck']);

        $response = $this->getJson('/api/machines/register')->assertOk();

        $this->assertSame(3, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.by_status.active'));
        $this->assertSame(1, $response->json('data.by_status.sold'));
        $this->assertSame(0, $response->json('data.by_status.maintenance'));
        $this->assertEqualsWithDelta(175000, $response->json('data.purchase_value.0.total'), 0.01);
        $this->assertSame('excavator', $response->json('data.by_type.0.machine_type'));
        $this->assertSame(2, $response->json('data.by_type.0.count'));
        $this->assertSame('Mine A', $response->json('data.by_worksite.0.name'));
        $this->assertSame(3, $response->json('data.unlinked_purchases'));
    }

    public function test_attachment_is_stored_downloaded_and_removed(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $machine = Machine::factory()->create();

        $attachment = $this->postJson("/api/machines/{$machine->id}/attachments", [
            'file' => UploadedFile::fake()->create('purchase-invoice.pdf', 120, 'application/pdf'),
            'kind' => 'invoice',
            'label' => 'Purchase invoice',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'invoice')
            ->assertJsonPath('data.original_name', 'purchase-invoice.pdf')
            ->json('data');

        $stored = $machine->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($stored->file_path);

        $this->getJson("/api/machines/{$machine->id}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.id', $attachment['id']);

        $this->get("/api/machines/{$machine->id}/attachments/{$attachment['id']}")
            ->assertOk()
            ->assertDownload('purchase-invoice.pdf');

        $this->deleteJson("/api/machines/{$machine->id}/attachments/{$attachment['id']}")->assertOk();

        Storage::disk('local')->assertMissing($stored->file_path);
        $this->assertDatabaseCount('prilozi', 0);
    }

    public function test_attachment_type_is_restricted(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $machine = Machine::factory()->create();

        $this->postJson("/api/machines/{$machine->id}/attachments", [
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('prilozi', 0);
    }

    public function test_attachment_of_another_machine_is_not_reachable(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $machine = Machine::factory()->create();
        $other = Machine::factory()->create();

        $id = $this->postJson("/api/machines/{$machine->id}/attachments", [
            'file' => UploadedFile::fake()->create('warranty.pdf', 20, 'application/pdf'),
            'kind' => 'warranty',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/machines/{$other->id}/attachments/{$id}")->assertStatus(404);
    }

    public function test_deleting_a_machine_removes_its_files(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $machine = Machine::factory()->create();

        $this->postJson("/api/machines/{$machine->id}/attachments", [
            'file' => UploadedFile::fake()->create('customs.pdf', 20, 'application/pdf'),
            'kind' => 'customs',
        ])->assertCreated();

        $path = $machine->attachments()->firstOrFail()->file_path;

        $this->deleteJson("/api/machines/{$machine->id}")->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('masine', 0);
        $this->assertDatabaseCount('prilozi', 0);
    }

    public function test_machines_require_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/machines')->assertStatus(403);
        $this->getJson('/api/machines/register')->assertStatus(403);
    }
}
