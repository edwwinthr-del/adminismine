<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ReceivableInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceivableTest extends TestCase
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

    public function test_create_receivable_starts_unpaid_with_full_remaining(): void
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create();

        $this->postJson('/api/receivables', [
            'client_id' => $client->id,
            'invoice_number' => 'R-1',
            'invoice_date' => '2026-07-01',
            'invoice_amount' => 1000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'unpaid')
            ->assertJsonPath('data.remaining_amount', 1000);
    }

    public function test_payment_and_deduction_reduce_remaining_and_settle(): void
    {
        $this->actingAsAdmin();
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 1000]);
        $invoice->recalculate();

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 400, 'payment_date' => '2026-07-05', 'account_id' => $this->nlbAccount()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.received_amount', 400)
            ->assertJsonPath('data.remaining_amount', 600)
            ->assertJsonPath('data.status', 'partial');

        $this->postJson("/api/receivables/{$invoice->id}/deductions", [
            'amount' => 100, 'deduction_date' => '2026-07-06', 'reason' => 'offset',
        ])
            ->assertCreated()
            ->assertJsonPath('data.deducted_amount', 100)
            ->assertJsonPath('data.remaining_amount', 500);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 500, 'payment_date' => '2026-07-10', 'account_id' => $this->cashAccount()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_settlement_exceeding_remaining_is_rejected(): void
    {
        $this->actingAsAdmin();
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 500]);
        $invoice->recalculate();

        $this->postJson("/api/receivables/{$invoice->id}/deductions", [
            'amount' => 600, 'deduction_date' => '2026-07-06',
        ])->assertStatus(422);
    }

    public function test_client_statement_has_totals_and_running_balance(): void
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create(['name' => 'Uniprom']);

        $invoice = ReceivableInvoice::factory()->create([
            'client_id' => $client->id,
            'invoice_amount' => 1000,
            'invoice_date' => '2026-07-01',
            'invoice_number' => 'U-1',
        ]);
        $invoice->payments()->create(['amount' => 300, 'currency' => 'EUR', 'payment_date' => '2026-07-05', 'account_id' => $this->nlbAccount()->id]);
        $invoice->deductions()->create(['amount' => 200, 'deduction_date' => '2026-07-06', 'reason' => 'offset']);
        $invoice->recalculate();

        $response = $this->getJson("/api/receivables/statement?client_id={$client->id}")->assertOk();

        $response->assertJsonPath('client.name', 'Uniprom')
            ->assertJsonPath('totals.invoiced', 1000)
            ->assertJsonPath('totals.received', 300)
            ->assertJsonPath('totals.deducted', 200)
            ->assertJsonPath('totals.remaining', 500);

        $entries = $response->json('entries');
        $this->assertCount(3, $entries);
        $this->assertEquals(500, $entries[count($entries) - 1]['balance']);
    }

    public function test_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker'); // no receivables permission
        Sanctum::actingAs($user);

        $this->getJson('/api/receivables')->assertStatus(403);
    }

    // ---- correcting a settled invoice ----------------------------------

    public function test_a_paid_receivable_can_still_be_corrected(): void
    {
        $this->actingAsAdmin();
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 1000]);
        $invoice->recalculate();

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000, 'payment_date' => '2026-07-05', 'account_id' => $this->nlbAccount()->id,
        ])->assertCreated()->assertJsonPath('data.status', 'paid');

        $this->putJson("/api/receivables/{$invoice->id}", ['invoice_number' => 'CORRECTED-9'])
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'CORRECTED-9')
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_the_amount_cannot_be_cut_below_payments_plus_deductions(): void
    {
        $this->actingAsAdmin();
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 1000]);
        $invoice->recalculate();

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 600, 'payment_date' => '2026-07-05', 'account_id' => $this->nlbAccount()->id,
        ])->assertCreated();
        $this->postJson("/api/receivables/{$invoice->id}/deductions", [
            'amount' => 200, 'deduction_date' => '2026-07-06', 'reason' => 'offset',
        ])->assertCreated();

        // 800 is settled, so 700 would leave the invoice smaller than its history.
        $this->putJson("/api/receivables/{$invoice->id}", ['invoice_amount' => 700])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_amount');

        $this->putJson("/api/receivables/{$invoice->id}", ['invoice_amount' => 800])->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_a_received_payment_can_be_corrected_and_removed(): void
    {
        $this->actingAsAdmin();
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 1000]);
        $invoice->recalculate();

        $paymentId = $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000, 'payment_date' => '2026-07-05', 'account_id' => $this->nlbAccount()->id,
        ])->json('data.payments.0.id');

        $this->putJson("/api/receivables/{$invoice->id}/payments/{$paymentId}", ['amount' => 400])
            ->assertOk()
            ->assertJsonPath('data.received_amount', 400)
            ->assertJsonPath('data.remaining_amount', 600);

        $this->deleteJson("/api/receivables/{$invoice->id}/payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.received_amount', 0)
            ->assertJsonPath('data.status', 'unpaid');
    }

    public function test_a_deduction_can_be_corrected_and_removed(): void
    {
        $this->actingAsAdmin();
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 1000]);
        $invoice->recalculate();

        $deductionId = $this->postJson("/api/receivables/{$invoice->id}/deductions", [
            'amount' => 300, 'deduction_date' => '2026-07-06', 'reason' => 'wrong amount',
        ])->json('data.deductions.0.id');

        $this->putJson("/api/receivables/{$invoice->id}/deductions/{$deductionId}", ['amount' => 100])
            ->assertOk()
            ->assertJsonPath('data.deducted_amount', 100)
            ->assertJsonPath('data.remaining_amount', 900);

        $this->deleteJson("/api/receivables/{$invoice->id}/deductions/{$deductionId}")
            ->assertOk()
            ->assertJsonPath('data.deducted_amount', 0)
            ->assertJsonPath('data.remaining_amount', 1000);
    }
}
