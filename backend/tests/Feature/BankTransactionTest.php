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
        // Category pinned: it decides the sign, and two rows are only the same
        // movement if their amounts match.
        BankTransaction::factory()->create(['date' => '2026-07-05', 'category' => 'expense', 'cash_amount' => 500, 'nlb_amount' => 0, 'lovcen_amount' => 0]);
        BankTransaction::factory()->create(['date' => '2026-07-05', 'category' => 'expense', 'cash_amount' => 500, 'nlb_amount' => 0, 'lovcen_amount' => 0]);

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

    public function test_an_expense_typed_as_a_positive_number_is_stored_negative(): void
    {
        $this->actingAsAdmin();

        // The workbook habit: the category says which way the money went and the
        // amount is typed as a plain figure. Stored as-is it would be *added* to
        // the balance, which is what corrupted every derived total.
        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-02', 'cash_amount' => 300, 'category' => 'expense',
        ])->assertCreated()->assertJsonPath('data.cash_amount', -300);

        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-01', 'cash_amount' => -1000, 'category' => 'income',
        ])->assertCreated()->assertJsonPath('data.cash_amount', 1000);

        // 1000 in, 300 out — the balance falls by the expense rather than rising.
        $this->getJson('/api/bank-transactions/balances')->assertJsonPath('data.total', 700);
    }

    public function test_a_transfer_keeps_both_of_its_signs(): void
    {
        $this->actingAsAdmin();

        // Cash to the bank: one row, negative on one account and positive on the
        // other. Forcing a direction on it would destroy the movement.
        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-03', 'cash_amount' => -500, 'nlb_amount' => 500, 'category' => 'transfer',
        ])->assertCreated()
            ->assertJsonPath('data.cash_amount', -500)
            ->assertJsonPath('data.nlb_amount', 500);
    }

    public function test_the_ledger_carries_a_running_balance_in_date_order(): void
    {
        $this->actingAsAdmin();

        BankTransaction::factory()->create(['date' => '2026-07-01', 'category' => 'income', 'cash_amount' => 1000, 'nlb_amount' => 0, 'lovcen_amount' => 0]);
        BankTransaction::factory()->create(['date' => '2026-07-02', 'category' => 'expense', 'cash_amount' => 300, 'nlb_amount' => 0, 'lovcen_amount' => 0]);
        BankTransaction::factory()->create(['date' => '2026-07-03', 'category' => 'expense', 'cash_amount' => 200, 'nlb_amount' => 0, 'lovcen_amount' => 0]);

        // Newest first, so the running balance counts back down the page.
        $rows = collect($this->getJson('/api/bank-transactions')->json('data'))->pluck('running_balance')->all();

        $this->assertEquals([500, 700, 1000], $rows);
    }

    public function test_the_running_balance_is_the_whole_ledger_even_under_a_filter(): void
    {
        $this->actingAsAdmin();

        BankTransaction::factory()->create(['date' => '2026-07-01', 'category' => 'income', 'cash_amount' => 1000, 'nlb_amount' => 0, 'lovcen_amount' => 0]);
        BankTransaction::factory()->create(['date' => '2026-07-02', 'category' => 'expense', 'cash_amount' => 300, 'nlb_amount' => 0, 'lovcen_amount' => 0]);

        // Filtering to expenses must not make the balance read as though the
        // income never happened — it is the account's balance, not the filter's.
        $rows = $this->getJson('/api/bank-transactions?category=expense')->json('data');

        $this->assertCount(1, $rows);
        $this->assertEquals(700, $rows[0]['running_balance']);
    }

    public function test_a_transaction_can_be_edited(): void
    {
        $this->actingAsAdmin();
        $tx = BankTransaction::factory()->create([
            'date' => '2026-07-01', 'category' => 'expense', 'cash_amount' => 100, 'nlb_amount' => 0, 'lovcen_amount' => 0,
        ]);

        $this->putJson("/api/bank-transactions/{$tx->id}", [
            'description_1' => 'Corrected fuel invoice',
            'cash_amount' => 150,
        ])->assertOk()
            ->assertJsonPath('data.description_1', 'Corrected fuel invoice')
            ->assertJsonPath('data.cash_amount', -150);
    }

    public function test_editing_a_transfer_keeps_the_signs_it_was_given(): void
    {
        $this->actingAsAdmin();
        $tx = BankTransaction::factory()->create([
            'category' => 'transfer', 'cash_amount' => -500, 'nlb_amount' => 500, 'lovcen_amount' => 0,
        ]);

        // Only the description changes; a transfer's two signs are the movement
        // and must survive an edit that never mentions them.
        $this->putJson("/api/bank-transactions/{$tx->id}", ['description_1' => 'Cash banked'])
            ->assertOk()
            ->assertJsonPath('data.cash_amount', -500)
            ->assertJsonPath('data.nlb_amount', 500);
    }

    public function test_deleting_a_transaction_needs_the_right_password(): void
    {
        $this->actingAsAdmin();
        $tx = BankTransaction::factory()->create();

        $this->deleteJson("/api/bank-transactions/{$tx->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->deleteJson("/api/bank-transactions/{$tx->id}", ['current_password' => 'not-my-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertDatabaseHas('bankovne_transakcije', ['id' => $tx->id]);

        $this->deleteJson("/api/bank-transactions/{$tx->id}", ['current_password' => 'password'])->assertOk();
        $this->assertDatabaseMissing('bankovne_transakcije', ['id' => $tx->id]);
    }

    public function test_deleting_a_matched_transaction_releases_its_payment_without_unpaying_the_invoice(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 600]);
        $invoice->recalculate();
        $tx = BankTransaction::factory()->create([
            'category' => 'expense', 'nlb_amount' => 600, 'cash_amount' => 0, 'lovcen_amount' => 0,
        ]);

        $this->postJson("/api/bank-transactions/{$tx->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 600,
        ])->assertCreated();

        $this->deleteJson("/api/bank-transactions/{$tx->id}", ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('released_payments', 1);

        // The money was still paid; only the match is gone. No payment is left
        // pointing at a movement that no longer exists.
        $payment = $invoice->fresh()->payments()->first();
        $this->assertNotNull($payment);
        $this->assertNull($payment->bank_transaction_id);
        $this->assertSame('paid', $invoice->fresh()->status);
    }
}
