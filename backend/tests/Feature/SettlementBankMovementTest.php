<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\FlightTicket;
use App\Models\House;
use App\Models\Loan;
use App\Models\RentPayment;
use App\Models\SalaryPayment;
use App\Models\SocialAssistancePayment;
use App\Models\TravelExpense;
use App\Models\User;
use App\Models\UtilityBill;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every settlement reaches the bank ledger the same way.
 *
 * Payables and receivables could book the movement a payment records; the other
 * seven modules could not, so paying rent, a worker, a ticket, a travel expense,
 * a loan instalment or social assistance settled the record and left every
 * balance on the dashboard exactly where it was. These tests state the rule for
 * all of them at once: booking moves the dashboard, correcting follows the line,
 * removing withdraws it, and a movement typed off a bank statement is never
 * rewritten by the module that points at it.
 */
class SettlementBankMovementTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = '2026-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);
    }

    /** @return array{balance: float, income: float, expenses: float} */
    private function dashboard(): array
    {
        $data = $this->getJson('/api/dashboard?month='.self::MONTH)->assertOk()->json('data');

        return [
            'balance' => (float) $data['balances']['total'],
            'income' => (float) $data['cashflow']['month']['income'],
            'expenses' => (float) $data['cashflow']['month']['expenses'],
        ];
    }

    private function rent(float $amount = 400, string $currency = 'EUR'): RentPayment
    {
        $house = House::factory()->create(['name' => 'Kuca Niksic 1', 'currency' => $currency]);

        $rent = RentPayment::factory()->create([
            'house_id' => $house->id,
            'month' => self::MONTH.'-01',
            'rent_amount_due' => $amount,
            'currency' => $currency,
        ]);
        $rent->recalculate();

        return $rent->refresh();
    }

    public function test_paying_rent_books_a_housing_expense_the_dashboard_shows(): void
    {
        $rent = $this->rent();

        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::query()->sole();

        $this->assertSame('housing', $movement->category);
        $this->assertSame('Kuca Niksic 1', $movement->description_1);
        // Money out: the sign is the record's, not the operator's to state.
        $this->assertSame(-400.0, round((float) $movement->cash_amount, 2));

        $dashboard = $this->dashboard();
        $this->assertSame(-400.0, $dashboard['balance']);
        $this->assertSame(400.0, $dashboard['expenses']);
        $this->assertSame(0.0, $dashboard['income']);

        $this->assertSame('paid', $rent->fresh()->status);
    }

    public function test_paying_a_worker_books_a_payroll_expense(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Ali', 'last_name' => 'Yilmaz']);
        $salary = SalaryPayment::factory()->create([
            'employee_id' => $employee->id,
            'salary_month' => self::MONTH.'-01',
            'base_salary' => 900,
            'adjustments' => 0,
            'deductions' => 0,
            'currency' => 'EUR',
        ]);
        $salary->recalculate();

        $this->postJson("/api/salary-payments/{$salary->id}/payments", [
            'amount' => 900,
            'payment_date' => self::MONTH.'-07',
            'method' => 'nlb',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::query()->sole();

        $this->assertSame('payroll', $movement->category);
        $this->assertSame('Ali Yilmaz', $movement->description_1);
        $this->assertSame(-900.0, round((float) $movement->nlb_amount, 2));
        $this->assertSame(-900.0, $this->dashboard()['balance']);
    }

    public function test_paying_a_utility_bill_books_a_housing_expense(): void
    {
        $house = House::factory()->create(['name' => 'Kuca 2']);
        $bill = UtilityBill::factory()->create([
            'house_id' => $house->id,
            'billing_period' => self::MONTH.'-01',
            'amount' => 120,
            'currency' => 'EUR',
        ]);
        $bill->recalculate();

        $this->postJson("/api/housing/bills/{$bill->id}/payments", [
            'amount' => 120,
            'payment_date' => self::MONTH.'-06',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::query()->sole();

        $this->assertSame('housing', $movement->category);
        $this->assertSame(-120.0, round((float) $movement->cash_amount, 2));
        $this->assertSame(-120.0, $this->dashboard()['balance']);
    }

    public function test_paying_a_ticket_and_a_travel_expense_book_travel_expenses(): void
    {
        $employee = Employee::factory()->create();

        $ticket = FlightTicket::factory()->create([
            'employee_id' => $employee->id,
            'ticket_date' => self::MONTH.'-02',
            'currency' => 'EUR',
            'amount' => 200,
            'amount_eur' => 200,
            'exchange_rate' => null,
            'exchange_rate_date' => null,
            'remaining_amount' => 200,
            'reference' => 'TK-1042',
        ]);

        $expense = TravelExpense::factory()->create([
            'employee_id' => $employee->id,
            'expense_date' => self::MONTH.'-03',
            'currency' => 'EUR',
            'amount' => 60,
            'amount_eur' => 60,
            'exchange_rate' => null,
            'exchange_rate_date' => null,
            'remaining_amount' => 60,
        ]);

        $this->postJson("/api/travel/tickets/{$ticket->id}/payments", [
            'amount' => 200,
            'payment_date' => self::MONTH.'-08',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->postJson("/api/travel/expenses/{$expense->id}/payments", [
            'amount' => 60,
            'payment_date' => self::MONTH.'-08',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->assertSame(['travel', 'travel'], BankTransaction::query()->pluck('category')->all());
        $this->assertSame('TK-1042', BankTransaction::query()->first()->description_1);
        $this->assertSame(-260.0, $this->dashboard()['balance']);
    }

    public function test_a_repayment_direction_follows_the_loan_it_settles(): void
    {
        $borrowed = Loan::factory()->create([
            'direction' => 'received',
            'counterparty' => 'NORTH-EX',
            'reference_number' => null,
            'currency' => 'EUR',
            'original_amount' => 1000,
            'amount_eur' => 1000,
            'remaining_amount' => 1000,
            'loan_date' => self::MONTH.'-01',
        ]);

        $lent = Loan::factory()->create([
            'direction' => 'given',
            'currency' => 'EUR',
            'original_amount' => 500,
            'amount_eur' => 500,
            'remaining_amount' => 500,
            'loan_date' => self::MONTH.'-01',
        ]);

        // Paying back what the company borrowed is money out …
        $this->postJson("/api/loans/{$borrowed->id}/repayments", [
            'amount' => 100,
            'payment_date' => self::MONTH.'-09',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        // … and a loan the company gave being repaid is money coming back in.
        $this->postJson("/api/loans/{$lent->id}/repayments", [
            'amount' => 50,
            'payment_date' => self::MONTH.'-09',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movements = BankTransaction::query()->orderBy('id')->get();

        $this->assertSame('loan', $movements[0]->category);
        $this->assertSame('NORTH-EX', $movements[0]->description_1);
        $this->assertSame(-100.0, round((float) $movements[0]->cash_amount, 2));
        $this->assertSame(50.0, round((float) $movements[1]->cash_amount, 2));
        $this->assertSame(-50.0, $this->dashboard()['balance']);
    }

    public function test_social_assistance_books_its_own_movement(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Emre', 'last_name' => 'Kaya']);

        $this->postJson('/api/travel/social-assistance', [
            'employee_id' => $employee->id,
            'payment_date' => self::MONTH.'-09',
            'amount' => 150,
            'currency' => 'EUR',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::query()->sole();
        $payout = SocialAssistancePayment::query()->sole();

        $this->assertSame('travel', $movement->category);
        $this->assertSame('Emre Kaya', $movement->description_1);
        $this->assertSame(-150.0, round((float) $movement->cash_amount, 2));
        $this->assertSame($movement->id, $payout->bank_transaction_id);
        $this->assertSame(-150.0, $this->dashboard()['balance']);

        // The payout goes, and the movement it booked goes with it.
        $this->deleteJson("/api/travel/social-assistance/{$payout->id}")->assertOk();
        $this->assertSame(0, BankTransaction::count());
    }

    public function test_correcting_a_booked_payment_rewrites_its_movement(): void
    {
        $rent = $this->rent();

        $payment = $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated()->json('data.payments.0.id');

        $this->putJson("/api/housing/rent/{$rent->id}/payments/{$payment}", [
            'amount' => 250,
            'payment_date' => self::MONTH.'-06',
            'method' => 'nlb',
        ])->assertOk();

        $movement = BankTransaction::query()->sole();

        // The account it left is emptied, not left holding the old amount.
        $this->assertSame(0.0, round((float) $movement->cash_amount, 2));
        $this->assertSame(-250.0, round((float) $movement->nlb_amount, 2));
        $this->assertSame(self::MONTH.'-06', $movement->date->toDateString());
        $this->assertSame(-250.0, $this->dashboard()['balance']);
        $this->assertSame('partial', $rent->fresh()->status);
    }

    public function test_removing_a_booked_payment_withdraws_its_movement(): void
    {
        $rent = $this->rent();

        $payment = $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated()->json('data.payments.0.id');

        $this->deleteJson("/api/housing/rent/{$rent->id}/payments/{$payment}")->assertOk();

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame(0.0, $this->dashboard()['balance']);
        $this->assertSame('unpaid', $rent->fresh()->status);
    }

    public function test_deleting_an_obligation_takes_the_movements_it_booked(): void
    {
        $rent = $this->rent();

        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->deleteJson("/api/housing/rent/{$rent->id}")->assertOk();

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame(0.0, $this->dashboard()['balance']);
    }

    public function test_a_matched_statement_movement_is_never_rewritten_or_removed(): void
    {
        $rent = $this->rent();

        // Typed off a bank statement: the money left the account whatever later
        // happens to the obligation pointed at it.
        $statement = BankTransaction::factory()->create([
            'date' => self::MONTH.'-05',
            'category' => 'housing',
            'cash_amount' => -400,
            'nlb_amount' => 0,
            'lovcen_amount' => 0,
            'source' => 'manual',
        ]);

        $payment = $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'bank_transaction_id' => $statement->id,
        ])->assertCreated()->json('data.payments.0.id');

        $this->deleteJson("/api/housing/rent/{$rent->id}/payments/{$payment}")->assertOk();

        $this->assertSame(1, BankTransaction::count());
        $this->assertSame(-400.0, round((float) $statement->fresh()->cash_amount, 2));
        // Only the balance the statement itself accounts for.
        $this->assertSame(-400.0, $this->dashboard()['balance']);
    }

    public function test_booking_needs_an_account_and_cannot_be_asked_for_twice(): void
    {
        $rent = $this->rent();
        $statement = BankTransaction::factory()->create(['source' => 'manual']);

        // `other` names no account, so there is no column to book into.
        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 100,
            'payment_date' => self::MONTH.'-05',
            'method' => 'other',
            'book_bank_transaction' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('book_bank_transaction');

        // Booking a new movement and matching an existing one are two different
        // claims; asking for both would book the same money twice.
        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 100,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
            'bank_transaction_id' => $statement->id,
        ])->assertStatus(422)->assertJsonValidationErrors('book_bank_transaction');

        $this->assertSame(0, $rent->fresh()->payments()->count());
    }

    public function test_a_generated_movement_cannot_be_matched_by_a_second_settlement(): void
    {
        $rent = $this->rent();
        $other = $this->rent(300);

        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $generated = BankTransaction::query()->sole();

        $this->postJson("/api/housing/rent/{$other->id}/payments", [
            'amount' => 300,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'bank_transaction_id' => $generated->id,
        ])->assertStatus(422)->assertJsonValidationErrors('bank_transaction_id');
    }

    public function test_housing_costs_in_another_currency_are_totalled_in_eur(): void
    {
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => self::MONTH.'-01']);

        $this->rent(400);
        // 3,500 TRY is 100 EUR at the stored rate — a EUR-correct tile reads 500,
        // not 3,900.
        $this->rent(3500, 'TRY');

        $housing = $this->getJson('/api/dashboard?month='.self::MONTH)->assertOk()->json('data.housing');

        $this->assertSame(500.0, round((float) $housing['monthly_cost'], 2));
        $this->assertSame(500.0, round((float) $housing['unpaid_rent'], 2));
    }

    public function test_a_foreign_currency_rent_is_settled_at_what_it_cost_in_eur(): void
    {
        ExchangeRate::factory()->create(['rate' => 35.0, 'rate_date' => self::MONTH.'-01']);

        $rent = $this->rent(3500, 'TRY');

        $this->assertSame(100.0, round((float) $rent->amount_eur, 2));

        // The payment is entered in the lease's own currency and priced with the
        // same rate, so the obligation closes exactly.
        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 3500,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
            'book_bank_transaction' => true,
        ])->assertCreated();

        $movement = BankTransaction::query()->sole();

        $this->assertSame('TRY', $movement->currency);
        $this->assertSame(-3500.0, round((float) $movement->cash_amount, 2));
        $this->assertSame(-100.0, round((float) $movement->net_amount_eur, 2));
        $this->assertSame('paid', $rent->fresh()->status);
        $this->assertSame(-100.0, $this->dashboard()['balance']);
    }

    public function test_a_settlement_recorded_without_booking_still_leaves_the_ledger_alone(): void
    {
        $rent = $this->rent();

        // The operator types their statements by hand: nothing should be booked
        // twice just because the obligation was marked settled.
        $this->postJson("/api/housing/rent/{$rent->id}/payments", [
            'amount' => 400,
            'payment_date' => self::MONTH.'-05',
            'method' => 'cash',
        ])->assertCreated();

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame('paid', $rent->fresh()->status);
    }
}
