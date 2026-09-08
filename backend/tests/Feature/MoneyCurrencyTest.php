<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\ExchangeRate;
use App\Models\PayableInvoice;
use App\Models\Payment;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The money core in a currency that is not the accounting currency.
 *
 * `payable_invoices`, `receivable_invoices`, `payments` and `bank_transactions`
 * each carried a `currency` column and nothing else — no rate, no EUR figure —
 * while every balance and every dashboard number summed the raw amounts across
 * currencies. A 100,000 TRY payable (~2,600 EUR) settled as a 100,000 EUR
 * outflow. The API accepted `currency` on create and the import templates
 * offered EUR/TRY/USD, so nothing about this was theoretical.
 */
class MoneyCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** 1 EUR = 38.50 TRY, so 100,000 TRY is 2,597.40 EUR. */
    private const RATE = 38.5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);
    }

    private function rate(string $date = '2026-07-01', float $rate = self::RATE): ExchangeRate
    {
        return ExchangeRate::create([
            'base_currency' => 'EUR',
            'quote_currency' => 'TRY',
            'rate' => $rate,
            'rate_date' => $date,
        ]);
    }

    public function test_a_foreign_currency_payable_is_priced_in_eur_and_keeps_its_original(): void
    {
        $this->rate();

        $id = $this->postJson('/api/payables', [
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => '2026-07-15',
            'currency' => 'TRY',
            'original_amount' => 100000,
        ])->assertCreated()->json('data.id');

        $invoice = PayableInvoice::find($id);

        // The original is never rewritten; the EUR figure sits beside it with
        // the rate that produced it (rule 5).
        $this->assertSame('100000.00', (string) $invoice->original_amount);
        $this->assertSame('TRY', $invoice->currency);
        $this->assertSame(2597.4, (float) $invoice->amount_eur);
        $this->assertSame(self::RATE, (float) $invoice->exchange_rate);
        $this->assertSame('2026-07-01', $invoice->exchange_rate_date->toDateString());

        // Remaining is the EUR liability, not the lira figure.
        $this->assertSame(2597.4, (float) $invoice->remaining_amount);
    }

    /** The headline failure: a ~2,600 EUR bill reading as a 100,000 EUR outflow. */
    public function test_paying_a_lira_invoice_moves_the_balance_by_its_eur_value(): void
    {
        $this->rate();

        $invoice = PayableInvoice::create([
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => '2026-07-15',
            'currency' => 'TRY',
            'original_amount' => 100000,
        ]);
        $invoice->recalculate();

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 100000,
            'currency' => 'TRY',
            'payment_date' => '2026-07-20',
            'account_id' => $this->nlbAccount()->id,
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->assertSame('paid', $invoice->fresh()->status);

        $payment = Payment::sole();
        $this->assertSame('100000.00', (string) $payment->amount);
        $this->assertSame(2597.4, (float) $payment->amount_eur);

        // The generated movement prices the money the same way its payment did.
        $movement = BankTransaction::sole();
        $this->assertSame('TRY', $movement->currency);
        $this->assertSame(self::RATE, (float) $movement->exchange_rate);
        $this->assertSame(-100000.0, $this->amountOn($movement, $this->nlbAccount()));
        $this->assertSame(-2597.4, $this->amountEurOn($movement, $this->nlbAccount()));

        // What this whole change exists for.
        $this->assertSame(-2597.4, $this->balanceOfAccount($this->nlbAccount()));
        $this->assertSame(-2597.4, BankTransaction::accountBalances()['total']);

        $expenses = $this->getJson('/api/dashboard?month=2026-07')
            ->assertOk()->json('data.cashflow.month.expenses');
        $this->assertSame(2597.4, (float) $expenses);
    }

    /** A rate on the request wins — how an operator records what the bank charged. */
    public function test_a_pinned_rate_beats_the_stored_one(): void
    {
        $this->rate();

        $invoice = ReceivableInvoice::create([
            'client_id' => Client::factory()->create()->id,
            'invoice_date' => '2026-07-15',
            'currency' => 'TRY',
            'invoice_amount' => 38500,
            'exchange_rate' => 35.0,
        ]);

        $this->assertSame(1100.0, (float) $invoice->amount_eur);
        $this->assertSame(35.0, (float) $invoice->exchange_rate);
    }

    /** A currency the app cannot price is refused rather than silently treated as EUR. */
    public function test_an_unpriceable_currency_is_a_422_not_a_one_to_one_guess(): void
    {
        // No TRY rate stored at all.
        $this->postJson('/api/payables', [
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => '2026-07-15',
            'currency' => 'TRY',
            'original_amount' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('exchange_rate');

        $this->assertSame(0, PayableInvoice::count());
    }

    /** Everything in EUR behaves exactly as it did before the columns existed. */
    public function test_euro_records_are_untouched_and_store_no_rate(): void
    {
        $invoice = PayableInvoice::create([
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => '2026-07-15',
            'currency' => 'EUR',
            'original_amount' => 1000,
        ]);
        $invoice->recalculate();

        $this->assertSame(1000.0, (float) $invoice->amount_eur);
        // Null is the marker that nothing was converted.
        $this->assertNull($invoice->exchange_rate);
        $this->assertNull($invoice->exchange_rate_date);
        $this->assertSame(1000.0, (float) $invoice->remaining_amount);
    }

    /** Mixed currencies still add up to one running balance. */
    public function test_balances_add_up_across_currencies(): void
    {
        $this->rate();

        $cash = $this->cashAccount();

        BankTransaction::create([
            'date' => '2026-07-10', 'category' => 'income', 'currency' => 'EUR',
        ])->setLines([['account_id' => $cash->id, 'amount' => 1000]]);

        BankTransaction::create([
            'date' => '2026-07-11', 'category' => 'income', 'currency' => 'TRY',
        ])->setLines([['account_id' => $cash->id, 'amount' => 38500]]);

        // 1,000 EUR + (38,500 TRY = 1,000 EUR).
        $balances = collect(BankTransaction::accountBalances()['accounts'])->keyBy('id');
        $this->assertSame(2000.0, $balances[$cash->id]['balance']);
    }
}
