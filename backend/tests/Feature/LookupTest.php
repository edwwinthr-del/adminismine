<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The options behind every searchable dropdown. What matters here is that a
 * lookup stays small, stays inside the caller's permissions, and can always
 * name the record a form already points at.
 */
class LookupTest extends TestCase
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

    public function test_a_lookup_returns_labelled_options(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Acme Doo']);

        $this->getJson('/api/lookups/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.value', $supplier->id)
            ->assertJsonPath('data.0.label', 'Acme Doo')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_search_is_case_insensitive_and_matches_a_substring(): void
    {
        $this->actingAsAdmin();
        Supplier::factory()->create(['name' => 'ACME Doo']);
        Supplier::factory()->create(['name' => 'Other Ltd']);

        $data = $this->getJson('/api/lookups/suppliers?search=acme')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('ACME Doo', $data[0]['label']);
    }

    public function test_a_typed_wildcard_is_matched_literally(): void
    {
        $this->actingAsAdmin();
        Supplier::factory()->create(['name' => 'Acme Doo']);
        Supplier::factory()->create(['name' => '100% Rock']);

        // Unescaped, '%' would match every supplier.
        $data = $this->getJson('/api/lookups/suppliers?search=%')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('100% Rock', $data[0]['label']);
    }

    public function test_a_lookup_never_returns_more_than_the_page_asked_for(): void
    {
        $this->actingAsAdmin();
        Supplier::factory()->count(30)->create();

        $response = $this->getJson('/api/lookups/suppliers?limit=5')->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertTrue($response->json('meta.has_more'));
    }

    public function test_an_already_selected_record_is_returned_even_when_it_is_filtered_out(): void
    {
        $this->actingAsAdmin();
        $inactive = Supplier::factory()->create(['name' => 'Retired Supplier', 'is_active' => false]);
        Supplier::factory()->create(['name' => 'Current Supplier', 'is_active' => true]);

        // Without `include`, an edit form would blank its own selection.
        $data = $this->getJson("/api/lookups/suppliers?active_only=1&include[]={$inactive->id}")
            ->assertOk()->json('data');

        $this->assertContains($inactive->id, array_column($data, 'value'));
    }

    public function test_a_lookup_carries_the_permission_of_the_module_behind_it(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer'); // payables.view only
        Sanctum::actingAs($user);

        $this->getJson('/api/lookups/suppliers')->assertOk();
        $this->getJson('/api/lookups/clients')->assertForbidden();
        $this->getJson('/api/lookups/employees')->assertForbidden();
    }

    public function test_an_unknown_lookup_is_not_found(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/lookups/nonsense')->assertNotFound();
    }

    public function test_invoice_options_carry_the_balance_the_form_needs(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 900, 'invoice_number' => 'INV-7']);
        $invoice->recalculate();

        $option = $this->getJson('/api/lookups/payable-invoices?outstanding=1')->assertOk()->json('data.0');

        $this->assertStringContainsString('INV-7', $option['label']);
        $this->assertEquals(900, $option['meta']['remaining']);
    }

    public function test_bank_and_cash_movements_are_separate_lookups_of_one_table(): void
    {
        $this->actingAsAdmin();

        $cash = BankTransaction::factory()->onAccount($this->cashAccount(), -120)->create([]);
        $bank = BankTransaction::factory()->onAccount($this->nlbAccount(), -400)->create([]);

        $cashOptions = $this->getJson('/api/lookups/bank-transactions?account=cash')->json('data');
        $bankOptions = $this->getJson('/api/lookups/bank-transactions?account=bank')->json('data');

        $this->assertSame([$cash->id], array_column($cashOptions, 'value'));
        $this->assertSame([$bank->id], array_column($bankOptions, 'value'));
    }

    public function test_client_names_are_searchable_through_their_invoices(): void
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create(['name' => 'Uniprom']);
        PayableInvoice::factory()->create(['invoice_number' => 'P-1']);
        $invoice = ReceivableInvoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'R-1',
        ]);

        $data = $this->getJson('/api/lookups/receivable-invoices?search=uniprom')->assertOk()->json('data');

        $this->assertSame([$invoice->id], array_column($data, 'value'));
    }
}
