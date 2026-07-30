<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankTransactionTest extends TestCase
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

    public function test_create_transaction_and_account_balances(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-01', 'cash_amount' => 1000, 'category' => 'income', 'description_1' => 'Opening',
        ])->assertCreated();

        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-02', 'nlb_amount' => -200, 'category' => 'expense',
        ])->assertCreated();

        $this->getJson('/api/bank-transactions/balances')
            ->assertOk()
            ->assertJsonPath('data.cash', 1000)
            ->assertJsonPath('data.nlb', -200)
            ->assertJsonPath('data.total', 800);
    }

    public function test_zero_transaction_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/bank-transactions', ['date' => '2026-07-01', 'category' => 'other'])
            ->assertStatus(422);
    }

    public function test_uncategorized_transaction_is_flagged(): void
    {
        $this->actingAsAdmin();
        $tx = BankTransaction::factory()->create([
            'category' => null, 'cash_amount' => 50, 'nlb_amount' => 0, 'lovcen_amount' => 0,
        ]);

        $row = collect($this->getJson('/api/bank-transactions')->json('data'))->firstWhere('id', $tx->id);
        $this->assertTrue($row['is_uncategorized']);
    }

    public function test_match_creates_linked_payment_and_reduces_remaining(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 1000]);
        $invoice->recalculate();
        $tx = BankTransaction::factory()->create([
            'nlb_amount' => -600, 'cash_amount' => 0, 'lovcen_amount' => 0, 'category' => 'expense',
        ]);

        $this->postJson("/api/bank-transactions/{$tx->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 600,
        ])->assertCreated();

        $invoice->refresh();
        $this->assertEquals(400, (float) $invoice->remaining_amount);
        $this->assertSame('partial', $invoice->status);

        $payment = $invoice->payments()->first();
        $this->assertSame($tx->id, $payment->bank_transaction_id);
        $this->assertSame('nlb', $payment->method);
    }

    public function test_match_exceeding_remaining_is_rejected(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 500]);
        $invoice->recalculate();
        $tx = BankTransaction::factory()->create(['nlb_amount' => -600]);

        $this->postJson("/api/bank-transactions/{$tx->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 600,
        ])->assertStatus(422);
    }

    public function test_duplicate_transactions_are_flagged(): void
    {
        $this->actingAsAdmin();
        BankTransaction::factory()->create(['date' => '2026-07-05', 'cash_amount' => 500, 'nlb_amount' => 0, 'lovcen_amount' => 0]);
        BankTransaction::factory()->create(['date' => '2026-07-05', 'cash_amount' => 500, 'nlb_amount' => 0, 'lovcen_amount' => 0]);

        $flags = collect($this->getJson('/api/bank-transactions')->json('data'))->pluck('possible_duplicate')->all();
        $this->assertContains(true, $flags);
    }

    public function test_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/bank-transactions')->assertStatus(403);
    }
}
