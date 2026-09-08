<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\House;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\RentPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UtilityBill;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    /** A user with exactly one named permission and nothing else. */
    private function actingWithPermission(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_account_balances_are_summed_from_the_movements(): void
    {
        $this->actingAsAdmin();

        // Categories are explicit because they now fix the sign: `income` and
        // `expense` state a direction, `other` leaves the signs as entered
        // (BankTransaction::DIRECTIONS).
        $cash = $this->cashAccount();
        $nlb = $this->nlbAccount();
        $lovcen = $this->lovcenAccount();

        BankTransaction::factory()->onAccount($cash, 1000)->create(['category' => 'income']);
        BankTransaction::factory()->withLines([
            ['account_id' => $cash->id, 'amount' => -250],
            ['account_id' => $nlb->id, 'amount' => 5000],
        ])->create(['category' => 'other']);
        BankTransaction::factory()->onAccount($lovcen, 750)->create(['category' => 'income']);

        $response = $this->getJson('/api/dashboard')->assertOk();
        $balances = collect($response->json('data.balances.accounts'))->keyBy('id');

        $this->assertEquals(750.0, $balances[$cash->id]['balance']);
        $this->assertEquals(5000.0, $balances[$nlb->id]['balance']);
        $this->assertEquals(750.0, $balances[$lovcen->id]['balance']);
        $response->assertJsonPath('data.balances.total', 6500);
    }

    public function test_each_account_carries_its_balance_month_by_month(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();

        BankTransaction::factory()->onAccount($cash, 1000)->create([
            'date' => '2026-05-10',
            'category' => 'income',
        ]);
        BankTransaction::factory()->onAccount($cash, -400)->create([
            'date' => '2026-07-04',
            'category' => 'expense',
        ]);

        $response = $this->getJson('/api/dashboard?month=2026-08')->assertOk();
        $account = collect($response->json('data.balances.accounts'))->firstWhere('id', $cash->id);

        // March through August. The balance carries forward through the months
        // nothing happened in — which is the whole difference between a balance
        // history and six monthly totals.
        $this->assertEquals([0, 0, 1000, 1000, 600, 600], $account['trend']);
        $this->assertEquals([0, 0, 1000, 1000, 600, 600], $response->json('data.balances.trend'));
    }

    public function test_the_series_opens_from_everything_before_the_window(): void
    {
        $this->actingAsAdmin();
        $cash = $this->cashAccount();

        // Well before the six months the dashboard shows: it is not a point on
        // the line, it is where the line starts.
        BankTransaction::factory()->onAccount($cash, 2500)->create([
            'date' => '2025-11-02',
            'category' => 'income',
        ]);

        $response = $this->getJson('/api/dashboard?month=2026-08')->assertOk();
        $account = collect($response->json('data.balances.accounts'))->firstWhere('id', $cash->id);

        $this->assertEquals([2500, 2500, 2500, 2500, 2500, 2500], $account['trend']);
        $this->assertEquals(2500, $account['balance']);
    }

    public function test_monthly_income_and_expenses_split_by_sign(): void
    {
        $this->actingAsAdmin();

        BankTransaction::factory()->onAccount($this->cashAccount(), 2000)->create([
            'date' => '2026-07-05',
            'category' => 'income',
        ]);
        BankTransaction::factory()->onAccount($this->cashAccount(), -800)->create([
            'date' => '2026-07-10',
            'category' => 'expense',
        ]);
        // Another month must not leak in.
        BankTransaction::factory()->onAccount($this->cashAccount(), 9999)->create([
            'date' => '2026-06-10',
        ]);

        $this->getJson('/api/dashboard?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.cashflow.month.income', 2000)
            ->assertJsonPath('data.cashflow.month.expenses', 800)
            ->assertJsonPath('data.cashflow.month.net', 1200);
    }

    public function test_transfers_are_left_out_of_income_and_expenses(): void
    {
        $this->actingAsAdmin();

        // Cash moved to the bank: real movements, but not income or expense.
        BankTransaction::factory()->transfer($this->cashAccount(), $this->nlbAccount(), 1000)->create([
            'date' => '2026-07-05',
            'category' => 'transfer',
        ]);

        $this->getJson('/api/dashboard?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.cashflow.month.income', 0)
            ->assertJsonPath('data.cashflow.month.expenses', 0)
            // The money still exists — it just did not come from anywhere.
            ->assertJsonPath('data.balances.total', 0);
    }

    public function test_the_trend_covers_six_months_ending_with_the_selected_one(): void
    {
        $this->actingAsAdmin();

        BankTransaction::factory()->onAccount($this->cashAccount(), 500)->create([
            'date' => '2026-05-10',
            // Pinned: the factory picks a random category, and a 'transfer' is
            // deliberately excluded from income — leaving it random makes this flaky.
            'category' => 'income',
        ]);

        $response = $this->getJson('/api/dashboard?month=2026-07')->assertOk();

        $trend = $response->json('data.cashflow.trend');
        $this->assertCount(6, $trend);
        $this->assertSame('2026-02-01', $trend[0]['month']);
        $this->assertSame('2026-07-01', $trend[5]['month']);
        // Empty months are present as zeroes so the chart has no gaps.
        $this->assertSame(0, $trend[0]['income']);
        $this->assertSame(500, $trend[3]['income']);
    }

    public function test_outstanding_payables_and_receivables_are_reported(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::factory()->create();
        $overdue = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 1000,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $overdue->recalculate();
        $current = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 400,
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $current->recalculate();

        $client = Client::factory()->create();
        $receivable = ReceivableInvoice::factory()->create([
            'client_id' => $client->id,
            'invoice_amount' => 2500,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);
        $receivable->recalculate();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.payables.outstanding', 1400)
            ->assertJsonPath('data.payables.overdue', 1000)
            ->assertJsonPath('data.payables.overdue_count', 1)
            ->assertJsonPath('data.receivables.outstanding', 2500)
            ->assertJsonPath('data.receivables.overdue', 2500);
    }

    public function test_receivables_are_broken_down_by_client_biggest_first(): void
    {
        $this->actingAsAdmin();

        $uniprom = Client::factory()->create(['name' => 'Uniprom']);
        $smaller = Client::factory()->create(['name' => 'Other DOO']);

        foreach ([[$uniprom, 5000], [$smaller, 900]] as [$client, $amount]) {
            $invoice = ReceivableInvoice::factory()->create([
                'client_id' => $client->id,
                'invoice_amount' => $amount,
            ]);
            $invoice->recalculate();
        }

        $response = $this->getJson('/api/dashboard')->assertOk();

        $byClient = $response->json('data.receivables.by_client');
        $this->assertCount(2, $byClient);
        // Biggest balance leads, which is how the Uniprom figure surfaces
        // without the product hardcoding a customer name.
        $this->assertSame('Uniprom', $byClient[0]['name']);
        $this->assertSame(5000, $byClient[0]['outstanding']);
        $this->assertSame('Other DOO', $byClient[1]['name']);
    }

    public function test_housing_cost_covers_rent_plus_bills_for_the_month(): void
    {
        $this->actingAsAdmin();

        $house = House::factory()->create();
        $rent = RentPayment::factory()->create([
            'house_id' => $house->id,
            'month' => '2026-07-01',
            'rent_amount_due' => 450,
        ]);
        $rent->recalculate();

        UtilityBill::factory()->create([
            'house_id' => $house->id,
            'billing_period' => '2026-07-01',
            'amount' => 120,
            'paid_amount' => 0,
            'remaining_amount' => 120,
        ]);
        // A different month stays out.
        UtilityBill::factory()->create([
            'house_id' => $house->id,
            'billing_period' => '2026-06-01',
            'amount' => 999,
        ]);

        $this->getJson('/api/dashboard?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.housing.monthly_cost', 570)
            ->assertJsonPath('data.housing.unpaid_rent', 450)
            ->assertJsonPath('data.housing.unpaid_bills', 120);
    }

    public function test_recent_transactions_are_the_newest_ten(): void
    {
        $this->actingAsAdmin();

        foreach (range(1, 12) as $day) {
            BankTransaction::factory()->create([
                'date' => sprintf('2026-07-%02d', $day),
                'description_1' => "Movement {$day}",
            ]);
        }

        $response = $this->getJson('/api/dashboard')->assertOk();

        $recent = $response->json('data.recent_transactions');
        $this->assertCount(10, $recent);
        $this->assertSame('Movement 12', $recent[0]['description']);
    }

    public function test_duplicate_and_unmatched_movements_are_flagged(): void
    {
        $this->actingAsAdmin();

        // Same date and same amounts entered twice. The category is pinned
        // because it now decides the sign (BankTransaction::DIRECTIONS), and two
        // rows only count as the same movement if their amounts match.
        foreach (range(1, 2) as $ignored) {
            BankTransaction::factory()->onAccount($this->cashAccount(), 300)->create([
                'date' => '2026-07-05',
                'category' => 'expense',
            ]);
        }

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.alerts.duplicate_entries', 2)
            // Neither has anything matched against it.
            ->assertJsonPath('data.alerts.unmatched_payments', 2)
            ->assertJsonPath('data.recent_transactions.0.possible_duplicate', true);
    }

    public function test_matching_a_movement_clears_it_from_the_unmatched_alert(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::factory()->create();
        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 500,
        ]);
        $invoice->recalculate();

        $transaction = BankTransaction::factory()->onAccount($this->cashAccount(), -500)->create([
            'date' => '2026-07-05',
        ]);

        $this->getJson('/api/dashboard')->assertJsonPath('data.alerts.unmatched_payments', 1);

        $invoice->payments()->create([
            'amount' => 500,
            'currency' => 'EUR',
            'payment_date' => '2026-07-05',
            'account_id' => $this->cashAccount()->id,
            'bank_transaction_id' => $transaction->id,
        ]);

        $this->getJson('/api/dashboard')->assertJsonPath('data.alerts.unmatched_payments', 0);
    }

    public function test_invoices_without_a_number_are_counted(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::factory()->create();
        PayableInvoice::factory()->create(['supplier_id' => $supplier->id, 'invoice_number' => null]);
        PayableInvoice::factory()->create(['supplier_id' => $supplier->id, 'invoice_number' => 'F-1']);

        $client = Client::factory()->create();
        ReceivableInvoice::factory()->create(['client_id' => $client->id, 'invoice_number' => null]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.alerts.missing_invoice_numbers', 2);
    }

    public function test_a_user_only_gets_the_sections_they_may_see(): void
    {
        $this->actingWithPermission('payables.view');

        BankTransaction::factory()->onAccount($this->cashAccount(), 1000)->create([]);
        $supplier = Supplier::factory()->create();
        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 200,
            // Pinned: the factory randomizes the due date, and this test asserts
            // an exact overdue count.
            'due_date' => now()->addDays(20)->toDateString(),
        ]);
        $invoice->recalculate();

        $response = $this->getJson('/api/dashboard')->assertOk();

        $response->assertJsonPath('data.payables.outstanding', 200);
        // No bank permission: balances, cashflow and recent movements are absent,
        // not zeroed — a zero would still be a claim about data they cannot open.
        $response->assertJsonMissingPath('data.balances');
        $response->assertJsonMissingPath('data.cashflow');
        $response->assertJsonMissingPath('data.recent_transactions');
        $response->assertJsonMissingPath('data.receivables');
        $response->assertJsonMissingPath('data.housing');
        $response->assertJsonMissingPath('data.alerts.duplicate_entries');
        $response->assertJsonPath('data.alerts.overdue_payables', 0);
    }

    public function test_an_admin_gets_every_section(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard')->assertOk();

        foreach (['balances', 'cashflow', 'recent_transactions', 'payables', 'receivables', 'housing', 'alerts'] as $section) {
            $response->assertJsonStructure(['data' => [$section]]);
        }
    }

    public function test_the_dashboard_needs_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }
}
