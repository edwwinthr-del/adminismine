<?php

namespace Tests\Feature;

use App\Models\CustomsDocument;
use App\Models\Machine;
use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomsDocumentTest extends TestCase
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

    public function test_cmr_is_created_and_linked_to_a_machine_and_its_invoice(): void
    {
        $this->actingAsAdmin();

        $customsAgency = Supplier::factory()->create(['name' => 'Adriatic Customs']);
        $invoice = PayableInvoice::factory()->create(['invoice_number' => 'MACH-INV-1']);
        $machine = Machine::factory()->create(['payable_invoice_id' => $invoice->id]);

        $this->postJson('/api/customs-documents', [
            'document_type' => 'cmr',
            'document_number' => 'DOC-1',
            'cmr_number' => 'CMR-556677',
            'cmr_date' => '2026-04-02',
            'shipment_date' => '2026-04-03',
            'customs_company_id' => $customsAgency->id,
            'customs_invoice_number' => 'CI-90',
            'sender' => 'Komatsu Europe',
            'receiver' => 'AdminisMine DOO',
            'carrier_name' => 'Balkan Trans',
            'vehicle_plate' => 'PG-123-AB',
            'driver_name' => 'Marko Petrovic',
            'goods_description' => 'Excavator PC210',
            'quantity' => 1,
            'unit' => 'pcs',
            'origin_place' => 'Germany',
            'destination_place' => 'Niksic',
            'machine_id' => $machine->id,
            'payable_invoice_id' => $invoice->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.customs_company_label', 'Adriatic Customs')
            ->assertJsonPath('data.machine.id', $machine->id)
            ->assertJsonPath('data.payable_invoice.invoice_number', 'MACH-INV-1')
            ->assertJsonPath('data.has_scan', false);
    }

    public function test_a_cmr_requires_its_number_but_other_types_do_not(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/customs-documents', ['document_type' => 'cmr', 'document_number' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cmr_number');

        $this->postJson('/api/customs-documents', [
            'document_type' => 'delivery_note',
            'document_number' => 'DN-5',
        ])->assertCreated();
    }

    public function test_document_type_and_status_must_be_canonical(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/customs-documents', ['document_type' => 'otpremnica'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document_type');

        $this->postJson('/api/customs-documents', [
            'document_type' => 'packing_list',
            'status' => 'primljeno',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_free_text_customs_company_is_used_when_not_a_supplier(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/customs-documents', [
            'document_type' => 'customs_declaration',
            'customs_company_name' => 'Border agency Bar',
        ])
            ->assertCreated()
            ->assertJsonPath('data.customs_company_label', 'Border agency Bar');
    }

    public function test_status_moves_through_the_review_flow(): void
    {
        $this->actingAsAdmin();
        $document = CustomsDocument::factory()->create(['status' => 'draft']);

        foreach (['received', 'checked', 'completed', 'archived'] as $status) {
            $this->putJson("/api/customs-documents/{$document->id}", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }
    }

    public function test_search_covers_every_identifier_a_clerk_may_have(): void
    {
        $this->actingAsAdmin();

        $machine = Machine::factory()->create(['serial_number' => 'SN-MACHINE-1']);
        $target = CustomsDocument::factory()->create([
            'cmr_number' => 'CMR-UNIQUE-1',
            'carrier_name' => 'Adria Logistics',
            'vehicle_plate' => 'BAR-777-ZZ',
            'sender' => 'Liebherr AG',
            'goods_description' => 'Hydraulic hammer',
            'machine_id' => $machine->id,
        ]);
        CustomsDocument::factory()->create(['carrier_name' => 'Other carrier']);

        foreach (['CMR-UNIQUE-1', 'Adria', 'BAR-777', 'Liebherr', 'hammer', 'SN-MACHINE-1'] as $term) {
            $ids = collect($this->getJson('/api/customs-documents?search='.urlencode($term))->assertOk()->json('data'))
                ->pluck('id')->all();

            $this->assertSame([$target->id], $ids, "search for '{$term}'");
        }
    }

    public function test_filters_by_type_status_machine_and_dates(): void
    {
        $this->actingAsAdmin();

        $machine = Machine::factory()->create();
        $cmr = CustomsDocument::factory()->create([
            'machine_id' => $machine->id, 'shipment_date' => '2026-05-10', 'cmr_date' => '2026-05-09',
        ]);
        // Dates are pinned, not left to the factory's random window: an
        // unpinned cmr_date can land on the one this test filters for and fail
        // the run on nothing but the date it happens to be run.
        CustomsDocument::factory()->checked()->create([
            'document_type' => 'packing_list', 'shipment_date' => '2026-06-20', 'cmr_date' => '2026-06-19',
        ]);

        $ids = collect($this->getJson('/api/customs-documents?document_type=cmr')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$cmr->id], $ids);

        $ids = collect($this->getJson("/api/customs-documents?machine_id={$machine->id}")->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$cmr->id], $ids);

        $ids = collect($this->getJson('/api/customs-documents?status=checked')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertCount(1, $ids);
        $this->assertNotContains($cmr->id, $ids);

        $ids = collect($this->getJson('/api/customs-documents?cmr_date=2026-05-09')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$cmr->id], $ids);

        $ids = collect($this->getJson('/api/customs-documents?date_from=2026-06-01')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertNotContains($cmr->id, $ids);
    }

    public function test_missing_paperwork_covers_flagged_and_unscanned_documents(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $flagged = CustomsDocument::factory()->missing()->create();
        $unscanned = CustomsDocument::factory()->checked()->create();
        $scanned = CustomsDocument::factory()->checked()->create();

        $this->postJson("/api/customs-documents/{$scanned->id}/attachments", [
            'file' => UploadedFile::fake()->create('cmr-scan.pdf', 50, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.kind', 'cmr');

        $ids = collect($this->getJson('/api/customs-documents?missing_paperwork=1')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$flagged->id, $unscanned->id], $ids);
        $this->assertNotContains($scanned->id, $ids);

        $this->getJson("/api/customs-documents/{$scanned->id}")
            ->assertOk()
            ->assertJsonPath('data.has_scan', true)
            ->assertJsonPath('data.attachments.0.original_name', 'cmr-scan.pdf');
    }

    public function test_register_counts_open_missing_and_unscanned(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $machine = Machine::factory()->create();
        CustomsDocument::factory()->missing()->create();
        CustomsDocument::factory()->create(['status' => 'draft', 'machine_id' => $machine->id]);
        $scanned = CustomsDocument::factory()->create(['status' => 'completed', 'document_type' => 'packing_list']);

        $this->postJson("/api/customs-documents/{$scanned->id}/attachments", [
            'file' => UploadedFile::fake()->create('papers.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $response = $this->getJson('/api/customs-documents/register')->assertOk();

        $this->assertSame(3, $response->json('data.total'));
        $this->assertSame(1, $response->json('data.missing'));
        $this->assertSame(2, $response->json('data.open'));       // missing + draft
        $this->assertSame(1, $response->json('data.unchecked'));  // draft
        $this->assertSame(2, $response->json('data.without_scan'));
        $this->assertSame(1, $response->json('data.linked_to_machine'));
        $this->assertSame('cmr', $response->json('data.by_type.0.document_type'));
        $this->assertCount(2, $response->json('data.attention'));
    }

    public function test_scan_is_downloadable_and_removable(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $document = CustomsDocument::factory()->create();

        $id = $this->postJson("/api/customs-documents/{$document->id}/attachments", [
            'file' => UploadedFile::fake()->create('declaration.pdf', 40, 'application/pdf'),
            'kind' => 'customs',
            'label' => 'Customs declaration',
        ])->assertCreated()->assertJsonPath('data.kind', 'customs')->json('data.id');

        $stored = $document->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($stored->file_path);

        $this->get("/api/customs-documents/{$document->id}/attachments/{$id}")
            ->assertOk()
            ->assertDownload('declaration.pdf');

        $this->deleteJson("/api/customs-documents/{$document->id}/attachments/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($stored->file_path);
    }

    public function test_attachment_of_another_document_is_not_reachable(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();

        $document = CustomsDocument::factory()->create();
        $other = CustomsDocument::factory()->create();

        $id = $this->postJson("/api/customs-documents/{$document->id}/attachments", [
            'file' => UploadedFile::fake()->create('cmr.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/customs-documents/{$other->id}/attachments/{$id}")->assertStatus(404);

        // A machine route must not reach a customs attachment either.
        $machine = Machine::factory()->create();
        $this->getJson("/api/machines/{$machine->id}/attachments/{$id}")->assertStatus(404);
    }

    public function test_deleting_a_document_removes_its_scans(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $document = CustomsDocument::factory()->create();

        $this->postJson("/api/customs-documents/{$document->id}/attachments", [
            'file' => UploadedFile::fake()->create('cmr.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $path = $document->attachments()->firstOrFail()->file_path;

        $this->deleteJson("/api/customs-documents/{$document->id}")->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('customs_documents', 0);
        $this->assertDatabaseCount('file_attachments', 0);
    }

    public function test_customs_documents_require_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/customs-documents')->assertStatus(403);
        $this->getJson('/api/customs-documents/register')->assertStatus(403);
    }
}
