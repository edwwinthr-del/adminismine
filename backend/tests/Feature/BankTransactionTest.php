<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    /** What a movement's payload says moved through one account. */
    private function line(TestResponse $response, int $accountId): array
    {
        $line = collect($response->json('data.lines'))->firstWhere('account_id', $accountId);

        $this->assertNotNull($line, "No line on account {$accountId}.");

        return $line;
    }

    /** The balance of one account, from the balances endpoint. */
    private function balanceOf(TestResponse $response, int $accountId): float
    {
        return (float) collect($response->json('data.accounts'))->firstWhere('id', $accountId)['balance'];
    }

    public function test_create_transaction_and_account_balances(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();
        $nlb = $this->nlbAccount();

        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-01', 'category' => 'income', 'description_1' => 'Opening',
            'lines' => [['account_id' => $cash->id, 'amount' => 1000]],
        ])->assertCreated();

        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-02', 'category' => 'expense',
            'lines' => [['account_id' => $nlb->id, 'amount' => -200]],
        ])->assertCreated();

        $balances = $this->getJson('/api/bank-transactions/balances')->assertOk();

        $this->assertSame(1000.0, $this->balanceOf($balances, $cash->id));
        $this->assertSame(-200.0, $this->balanceOf($balances, $nlb->id));
        $balances->assertJsonPath('data.total', 800);
    }

    public function test_an_account_with_no_movements_is_listed_at_zero(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();
        $unused = $this->bankAccount('Erste', 'bank');

        BankTransaction::factory()->onAccount($cash, 250)->create(['category' => 'income']);

        // A missing row and a zero balance are different claims: an account that
        // has just been opened should read as empty, not as absent.
        $balances = $this->getJson('/api/bank-transactions/balances')->assertOk();

        $this->assertSame(0.0, $this->balanceOf($balances, $unused->id));
    }

    public function test_zero_transaction_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/bank-transactions', ['date' => '2026-07-01', 'category' => 'other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        // A line naming an account but moving nothing is not a movement either.
        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-01', 'category' => 'other',
            'lines' => [['account_id' => $this->cashAccount()->id, 'amount' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_a_closed_account_cannot_take_a_new_movement(): void
    {
        $this->actingAsAdmin();
        $closed = $this->bankAccount('Old bank', 'bank');
        $closed->update(['is_active' => false]);

        $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-01', 'category' => 'expense',
            'lines' => [['account_id' => $closed->id, 'amount' => -100]],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.account_id');
    }

    public function test_uncategorized_transaction_is_flagged(): void
    {
        $this->actingAsAdmin();
        $tx = BankTransaction::factory()->amount(50)->create(['category' => null]);

        $row = collect($this->getJson('/api/bank-transactions')->json('data'))->firstWhere('id', $tx->id);
        $this->assertTrue($row['is_uncategorized']);
    }

    public function test_match_creates_linked_payment_and_reduces_remaining(): void
    {
        $this->actingAsAdmin();
        $nlb = $this->nlbAccount();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 1000]);
        $invoice->recalculate();
        $tx = BankTransaction::factory()->onAccount($nlb, -600)->create(['category' => 'expense']);

        $this->postJson("/api/bank-transactions/{$tx->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 600,
        ])->assertCreated();

        $invoice->refresh();
        $this->assertEquals(400, (float) $invoice->remaining_amount);
        $this->assertSame('partial', $invoice->status);

        $payment = $invoice->payments()->first();
        $this->assertSame($tx->id, $payment->bank_transaction_id);
        // The payment inherits the account the money actually moved through.
        $this->assertSame($nlb->id, (int) $payment->account_id);
    }

    public function test_a_transfer_has_nothing_to_allocate_against_an_invoice(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 500]);
        $invoice->recalculate();

        // Two lines, one each way: the money never left the company, so its net
        // is zero and there is nothing for an invoice to be settled out of.
        $tx = BankTransaction::factory()
            ->transfer($this->cashAccount(), $this->nlbAccount(), 500)
            ->create();

        $this->postJson("/api/bank-transactions/{$tx->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_match_exceeding_remaining_is_rejected(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 500]);
        $invoice->recalculate();
        $tx = BankTransaction::factory()->onAccount($this->nlbAccount(), -600)->create(['category' => 'expense']);

        $this->postJson("/api/bank-transactions/{$tx->id}/match", [
            'target' => 'payable', 'invoice_id' => $invoice->id, 'amount' => 600,
        ])->assertStatus(422);
    }

    public function test_duplicate_transactions_are_flagged(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();

        // Category pinned: it decides the sign, and two rows are only the same
        // movement if their amounts match.
        BankTransaction::factory()->onAccount($cash, 500)->create(['date' => '2026-07-05', 'category' => 'expense']);
        BankTransaction::factory()->onAccount($cash, 500)->create(['date' => '2026-07-05', 'category' => 'expense']);

        $flags = collect($this->getJson('/api/bank-transactions')->json('data'))->pluck('possible_duplicate')->all();
        $this->assertContains(true, $flags);
    }

    public function test_the_same_amount_on_different_accounts_is_not_a_duplicate(): void
    {
        $this->actingAsAdmin();

        // 500 out of the till and 500 out of the bank on one day is two real
        // movements. The signature is per account for exactly this reason.
        BankTransaction::factory()->onAccount($this->cashAccount(), 500)
            ->create(['date' => '2026-07-05', 'category' => 'expense']);
        BankTransaction::factory()->onAccount($this->nlbAccount(), 500)
            ->create(['date' => '2026-07-05', 'category' => 'expense']);

        $flags = collect($this->getJson('/api/bank-transactions')->json('data'))->pluck('possible_duplicate')->all();
        $this->assertNotContains(true, $flags);
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
        $cash = $this->cashAccount();

        // The workbook habit: the category says which way the money went and the
        // amount is typed as a plain figure. Stored as-is it would be *added* to
        // the balance, which is what corrupted every derived total.
        $expense = $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-02', 'category' => 'expense',
            'lines' => [['account_id' => $cash->id, 'amount' => 300]],
        ])->assertCreated();

        $this->assertEquals(-300.0, $this->line($expense, $cash->id)['amount']);
        $expense->assertJsonPath('data.net_amount', -300);

        $income = $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-01', 'category' => 'income',
            'lines' => [['account_id' => $cash->id, 'amount' => -1000]],
        ])->assertCreated();

        $this->assertEquals(1000.0, $this->line($income, $cash->id)['amount']);

        // 1000 in, 300 out — the balance falls by the expense rather than rising.
        $this->getJson('/api/bank-transactions/balances')->assertJsonPath('data.total', 700);
    }

    public function test_a_transfer_keeps_both_of_its_signs(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();
        $nlb = $this->nlbAccount();

        // Cash to the bank: one movement, negative on one account and positive
        // on the other. Forcing a direction on it would destroy the movement.
        $response = $this->postJson('/api/bank-transactions', [
            'date' => '2026-07-03', 'category' => 'transfer',
            'lines' => [
                ['account_id' => $cash->id, 'amount' => -500],
                ['account_id' => $nlb->id, 'amount' => 500],
            ],
        ])->assertCreated();

        $this->assertEquals(-500.0, $this->line($response, $cash->id)['amount']);
        $this->assertEquals(500.0, $this->line($response, $nlb->id)['amount']);
        $response->assertJsonPath('data.net_amount', 0);
    }

    public function test_the_ledger_carries_a_running_balance_in_date_order(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();

        BankTransaction::factory()->onAccount($cash, 1000)->create(['date' => '2026-07-01', 'category' => 'income']);
        BankTransaction::factory()->onAccount($cash, 300)->create(['date' => '2026-07-02', 'category' => 'expense']);
        BankTransaction::factory()->onAccount($cash, 200)->create(['date' => '2026-07-03', 'category' => 'expense']);

        // Newest first, so the running balance counts back down the page.
        $rows = collect($this->getJson('/api/bank-transactions')->json('data'))->pluck('running_balance')->all();

        $this->assertEquals([500, 700, 1000], $rows);
    }

    public function test_the_running_balance_is_the_whole_ledger_even_under_a_filter(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();

        BankTransaction::factory()->onAccount($cash, 1000)->create(['date' => '2026-07-01', 'category' => 'income']);
        BankTransaction::factory()->onAccount($cash, 300)->create(['date' => '2026-07-02', 'category' => 'expense']);

        // Filtering to expenses must not make the balance read as though the
        // income never happened — it is the account's balance, not the filter's.
        $rows = $this->getJson('/api/bank-transactions?category=expense')->json('data');

        $this->assertCount(1, $rows);
        $this->assertEquals(700, $rows[0]['running_balance']);
    }

    public function test_a_transaction_can_be_edited(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();
        $tx = BankTransaction::factory()->onAccount($cash, 100)
            ->create(['date' => '2026-07-01', 'category' => 'expense']);

        $response = $this->putJson("/api/bank-transactions/{$tx->id}", [
            'description_1' => 'Corrected fuel invoice',
            'lines' => [['account_id' => $cash->id, 'amount' => 150]],
        ])->assertOk()->assertJsonPath('data.description_1', 'Corrected fuel invoice');

        $this->assertEquals(-150.0, $this->line($response, $cash->id)['amount']);
    }

    public function test_moving_a_movement_to_another_account_empties_the_one_it_left(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();
        $nlb = $this->nlbAccount();
        $tx = BankTransaction::factory()->onAccount($cash, 400)->create(['category' => 'expense']);

        $response = $this->putJson("/api/bank-transactions/{$tx->id}", [
            'lines' => [['account_id' => $nlb->id, 'amount' => 400]],
        ])->assertOk();

        // The cash line is gone rather than left at its old amount — otherwise
        // the money would read as having left both accounts.
        $this->assertNull(collect($response->json('data.lines'))->firstWhere('account_id', $cash->id));
        $this->assertEquals(-400.0, $this->line($response, $nlb->id)['amount']);
        $this->assertSame(0.0, $this->balanceOf($this->getJson('/api/bank-transactions/balances'), $cash->id));
    }

    public function test_editing_a_transfer_keeps_the_signs_it_was_given(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();
        $nlb = $this->nlbAccount();
        $tx = BankTransaction::factory()->transfer($cash, $nlb, 500)->create();

        // Only the description changes; a transfer's two signs are the movement
        // and must survive an edit that never mentions them.
        $response = $this->putJson("/api/bank-transactions/{$tx->id}", ['description_1' => 'Cash banked'])
            ->assertOk();

        $this->assertEquals(-500.0, $this->line($response, $cash->id)['amount']);
        $this->assertEquals(500.0, $this->line($response, $nlb->id)['amount']);
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

    public function test_deleting_a_transaction_takes_its_lines_with_it(): void
    {
        $this->actingAsAdmin();
        $tx = BankTransaction::factory()->amount(-250)->create(['category' => 'expense']);

        $this->assertDatabaseHas('stavke_transakcija', ['bank_transaction_id' => $tx->id]);

        $this->deleteJson("/api/bank-transactions/{$tx->id}", ['current_password' => 'password'])->assertOk();

        // A line whose movement is gone would still be counted in the account's
        // balance.
        $this->assertDatabaseMissing('stavke_transakcija', ['bank_transaction_id' => $tx->id]);
    }

    public function test_deleting_a_matched_transaction_releases_its_payment_without_unpaying_the_invoice(): void
    {
        $this->actingAsAdmin();
        $invoice = PayableInvoice::factory()->create(['original_amount' => 600]);
        $invoice->recalculate();
        $tx = BankTransaction::factory()->onAccount($this->nlbAccount(), 600)->create(['category' => 'expense']);

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
