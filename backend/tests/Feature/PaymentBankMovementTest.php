<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\PayableInvoice;
use App\Models\Payment;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentBankMovement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settling an invoice and the bank ledger, joined up.
 *
 * The gap these cover: every money figure the dashboard shows is summed from
 * `bank_transactions`, so a payment recorded against an invoice and nowhere
 * else moved the invoice to `paid` while leaving every balance untouched. A
 * payment may now say that it *is* the record of the movement, and the tests
 * below pin both halves of that — the movement it writes, and the fact that a
 * movement typed off a real bank statement is never rewritten to follow an
 * invoice.
 */
class PaymentBankMovementTest extends TestCase
{
    use RefreshDatabase;

    /** The factory's password, for the delete routes that re-authenticate. */
    private const CONFIRM = ['current_password' => 'password'];

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

    private function receivable(float $amount = 1000): ReceivableInvoice
    {
        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => $amount]);
        $invoice->recalculate();

        return $invoice;
    }

    private function payable(float $amount = 1000): PayableInvoice
    {
        $invoice = PayableInvoice::factory()->create(['original_amount' => $amount]);
        $invoice->recalculate();

        return $invoice;
    }

    public function test_receipt_books_an_income_movement_into_the_named_account(): void
    {
        $this->actingAsAdmin();
        $client = Client::factory()->create();
        $invoice = ReceivableInvoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'R-77',
            'invoice_amount' => 1000,
        ]);
        $invoice->recalculate();

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 400,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::sole();

        // Money arriving against a receivable is income, and positive: the sign
        // is the invoice's direction, never something the caller states.
        $this->assertSame('income', $movement->category);
        $this->assertSame(400.0, (float) $movement->nlb_amount);
        $this->assertSame(0.0, (float) $movement->cash_amount);
        $this->assertSame(0.0, (float) $movement->lovcen_amount);
        $this->assertSame('2026-07-05', $movement->date->toDateString());
        $this->assertSame($client->id, $movement->client_id);
        $this->assertSame(PaymentBankMovement::SOURCE, $movement->source);
        // Text comes off the records, never composed — so it reads the same in
        // all three languages (rule 4).
        $this->assertSame('R-77', $movement->description_1);

        $this->assertSame($movement->id, Payment::sole()->bank_transaction_id);
    }

    public function test_payment_out_books_an_expense_movement(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create();
        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 1000,
        ]);
        $invoice->recalculate();

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 250,
            'payment_date' => '2026-07-06',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::sole();

        $this->assertSame('expense', $movement->category);
        $this->assertSame(-250.0, (float) $movement->cash_amount);
        $this->assertSame($supplier->id, $movement->supplier_id);
        $this->assertSame(PaymentBankMovement::SOURCE, $movement->source);
    }

    public function test_a_booked_receipt_reaches_the_balances_and_the_dashboard(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000,
            'payment_date' => now()->toDateString(),
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        // The whole point: the figures the dashboard reads are aggregates over
        // bank_transactions, so this is what "the receipt is visible" means.
        $this->assertSame(1000.0, BankTransaction::accountBalances()['nlb']);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balances.nlb', 1000)
            ->assertJsonPath('data.balances.total', 1000)
            ->assertJsonPath('data.cashflow.month.income', 1000)
            ->assertJsonPath('data.receivables.outstanding', 0);
    }

    public function test_booking_is_refused_when_no_account_is_named(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable();

        // `other` names no account column, so there is nothing to book into.
        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 100,
            'payment_date' => '2026-07-05',
            'method' => 'other',
            'book_bank_transaction' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('book_bank_transaction');

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_booking_and_matching_at_once_is_refused(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable();
        $existing = BankTransaction::factory()->create();

        // Both would put the same money in the ledger twice.
        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 100,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'bank_transaction_id' => $existing->id,
            'book_bank_transaction' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('book_bank_transaction');

        $this->assertSame(1, BankTransaction::count());
    }

    public function test_correcting_the_payment_amount_corrects_the_booked_movement(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 400,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $payment = Payment::sole();

        $this->putJson("/api/receivables/{$invoice->id}/payments/{$payment->id}", [
            'amount' => 650,
            'payment_date' => '2026-07-09',
        ])->assertOk();

        $movement = BankTransaction::sole();

        $this->assertSame(650.0, (float) $movement->nlb_amount);
        $this->assertSame('2026-07-09', $movement->date->toDateString());
        // Still one row: a correction edits the line that was wrong, it never
        // books a compensating opposite entry.
        $this->assertSame(1, BankTransaction::count());
        $this->assertSame(650.0, BankTransaction::accountBalances()['nlb']);
    }

    public function test_moving_a_payment_to_another_account_empties_the_one_it_left(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 300,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $payment = Payment::sole();

        $this->putJson("/api/receivables/{$invoice->id}/payments/{$payment->id}", [
            'method' => 'cash',
        ])->assertOk();

        $movement = BankTransaction::sole();

        $this->assertSame(300.0, (float) $movement->cash_amount);
        $this->assertSame(0.0, (float) $movement->nlb_amount);
        $this->assertSame(0.0, BankTransaction::accountBalances()['nlb']);
    }

    public function test_switching_a_payment_to_other_withdraws_the_movement_it_booked(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 300,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $payment = Payment::sole();

        // The payment stops claiming money moved through an account, so the row
        // that said it did cannot stay behind.
        $this->putJson("/api/receivables/{$invoice->id}/payments/{$payment->id}", [
            'method' => 'other',
        ])->assertOk();

        $this->assertSame(0, BankTransaction::count());
        $this->assertNull($payment->fresh()->bank_transaction_id);
        // The invoice is untouched by any of that: it is still settled by 300.
        $this->assertSame(300.0, (float) $invoice->fresh()->received_amount);
    }

    public function test_removing_the_payment_removes_the_movement_it_booked(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $payment = Payment::sole();

        $this->deleteJson("/api/receivables/{$invoice->id}/payments/{$payment->id}")->assertOk();

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame(0.0, BankTransaction::accountBalances()['total']);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_a_hand_entered_movement_survives_the_payment_matched_to_it(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);
        $statementRow = BankTransaction::factory()->create([
            'category' => 'income',
            'nlb_amount' => 1000,
            'source' => null,
        ]);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'bank_transaction_id' => $statementRow->id,
        ])->assertCreated();

        $payment = Payment::sole();

        $this->deleteJson("/api/receivables/{$invoice->id}/payments/{$payment->id}")->assertOk();

        // The money did arrive whatever happened to the invoice it was pointed
        // at; erasing it would put the app out of step with the bank.
        $this->assertModelExists($statementRow);
        $this->assertSame(1000.0, BankTransaction::accountBalances()['nlb']);
    }

    public function test_a_hand_entered_movement_is_not_rewritten_to_follow_the_invoice(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);
        $statementRow = BankTransaction::factory()->create([
            'category' => 'income',
            'nlb_amount' => 1000,
            'source' => null,
        ]);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'bank_transaction_id' => $statementRow->id,
        ])->assertCreated();

        $payment = Payment::sole();

        $this->putJson("/api/receivables/{$invoice->id}/payments/{$payment->id}", [
            'amount' => 400,
        ])->assertOk();

        // The payment was corrected; the bank statement was not.
        $this->assertSame(1000.0, (float) $statementRow->fresh()->nlb_amount);
        $this->assertSame(400.0, (float) $invoice->fresh()->received_amount);
    }

    public function test_deleting_an_invoice_takes_its_booked_movements_but_not_the_statement_rows(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);
        $statementRow = BankTransaction::factory()->create([
            'category' => 'income',
            'nlb_amount' => 400,
            'source' => null,
        ]);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 600,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 400,
            'payment_date' => '2026-07-06',
            'method' => 'nlb',
            'bank_transaction_id' => $statementRow->id,
        ])->assertCreated();

        $this->assertSame(1000.0, BankTransaction::accountBalances()['nlb']);

        $this->deleteJson("/api/receivables/{$invoice->id}")->assertOk();

        // The generated row went with the invoice that explained it; the bank's
        // own row stayed, and the balance is exactly the statement's 400.
        $this->assertModelExists($statementRow);
        $this->assertSame(1, BankTransaction::count());
        $this->assertSame(400.0, BankTransaction::accountBalances()['nlb']);
    }

    public function test_deleting_a_payable_takes_its_booked_movements(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->payable(500);

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 500,
            'payment_date' => '2026-07-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->assertSame(-500.0, BankTransaction::accountBalances()['cash']);

        $this->deleteJson("/api/payables/{$invoice->id}", self::CONFIRM)->assertOk();

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame(0.0, BankTransaction::accountBalances()['cash']);
    }

    public function test_not_booking_stays_the_default_and_touches_no_ledger(): void
    {
        $this->actingAsAdmin();
        $invoice = $this->receivable(1000);

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 1000,
            'payment_date' => '2026-07-05',
            'method' => 'nlb',
        ])->assertCreated();

        // Unchanged behaviour for every caller that does not ask: the operator
        // who types their statements by hand books nothing twice.
        $this->assertSame(0, BankTransaction::count());
        $this->assertNull(Payment::sole()->bank_transaction_id);
        $this->assertSame('paid', $invoice->fresh()->status);
    }
}
