<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Loan;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Reports\ReportRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
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

    public function test_the_catalogue_lists_every_registered_report(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports')->assertOk();
        $keys = collect($response->json('data'))->pluck('key');

        // The spec's report list, all present.
        foreach ([
            'monthly_cashflow', 'payables_aging', 'receivables_aging', 'supplier_statement',
            'client_statement', 'worker_payment_report', 'employee_salary_payment_report',
            'attendance_report', 'overtime_report', 'daily_earned_pay_report', 'worker_needs_report',
            'monthly_mined_goods_report', 'mining_engineer_production_report',
            'machine_equipment_register', 'customs_transport_document_register',
            'worker_housing_cost_report', 'unpaid_rent_and_bills_report',
            'travel_and_ticket_cost_report', 'loan_advance_balance_report',
        ] as $key) {
            $this->assertTrue($keys->contains($key), "Missing report: {$key}");
        }
    }

    public function test_every_report_runs_without_blowing_up_on_an_empty_database(): void
    {
        $this->actingAsAdmin();

        foreach (app(ReportRegistry::class)->all() as $key => $report) {
            $this->getJson("/api/reports/{$key}")
                ->assertOk()
                ->assertJsonPath('data.key', $key);
        }
    }

    public function test_the_catalogue_only_shows_reports_the_user_may_run(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['reports.view', 'payables.view']);
        Sanctum::actingAs($user);

        $keys = collect($this->getJson('/api/reports')->json('data'))->pluck('key');

        $this->assertTrue($keys->contains('payables_aging'));
        $this->assertTrue($keys->contains('supplier_statement'));
        // No housing permission, so the housing reports are not offered…
        $this->assertFalse($keys->contains('worker_housing_cost_report'));
        // …and cannot be run directly either.
        $this->getJson('/api/reports/worker_housing_cost_report')->assertForbidden();
    }

    public function test_payables_aging_buckets_by_how_late_an_invoice_is(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Acme DOO']);

        foreach ([5 => '1_30', 45 => '31_60', 200 => 'over_90'] as $daysLate => $expected) {
            $invoice = PayableInvoice::factory()->create([
                'supplier_id' => $supplier->id,
                'original_amount' => 100,
                'due_date' => now()->subDays($daysLate)->toDateString(),
            ]);
            $invoice->recalculate();
        }

        $rows = $this->getJson('/api/reports/payables_aging')->assertOk()->json('data.rows');

        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            ['1_30', '31_60', 'over_90'],
            collect($rows)->pluck('bucket')->all(),
        );
        // Days overdue is a count, so it is never summed into the totals.
        $totals = $this->getJson('/api/reports/payables_aging')->json('data.totals');
        $this->assertSame(300, $totals['outstanding']);
        $this->assertArrayNotHasKey('days_overdue', $totals);
    }

    public function test_a_statement_runs_a_balance_down_the_rows(): void
    {
        $this->actingAsAdmin();

        $client = Client::factory()->create(['name' => 'Uniprom']);
        $invoice = ReceivableInvoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => '1/2024',
            'invoice_date' => '2026-03-29',
            'invoice_amount' => 1000,
        ]);
        $invoice->recalculate();
        $invoice->payments()->create([
            'amount' => 400,
            'currency' => 'EUR',
            'payment_date' => '2026-04-22',
            'method' => 'nlb',
        ]);
        $invoice->recalculate();

        $data = $this->getJson("/api/reports/client_statement?client_id={$client->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['rows']);
        $this->assertSame(1000, $data['rows'][0]['balance']);
        $this->assertSame(600, $data['rows'][1]['balance']);
        // The closing balance, not a column sum.
        $this->assertSame(600, $data['totals']['balance']);
    }

    public function test_a_statement_with_no_party_chosen_returns_nothing(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/client_statement')
            ->assertOk()
            ->assertJsonPath('data.row_count', 0);
    }

    public function test_monthly_cashflow_covers_twelve_months_and_skips_transfers(): void
    {
        $this->actingAsAdmin();

        BankTransaction::factory()->create([
            'date' => '2026-03-10', 'cash_amount' => 5000, 'nlb_amount' => 0, 'lovcen_amount' => 0,
            'category' => 'income',
        ]);
        BankTransaction::factory()->create([
            'date' => '2026-03-15', 'cash_amount' => -2000, 'nlb_amount' => 0, 'lovcen_amount' => 0,
            'category' => 'expense',
        ]);
        BankTransaction::factory()->create([
            'date' => '2026-03-20', 'cash_amount' => -900, 'nlb_amount' => 900, 'lovcen_amount' => 0,
            'category' => 'transfer',
        ]);

        $rows = $this->getJson('/api/reports/monthly_cashflow?year=2026')->assertOk()->json('data.rows');

        $this->assertCount(12, $rows);
        $march = collect($rows)->firstWhere('month', '2026-03-01');
        $this->assertSame(5000, $march['income']);
        $this->assertSame(2000, $march['expenses']);
        $this->assertSame(3000, $march['net']);
    }

    public function test_loan_balances_report_shows_what_is_still_owed(): void
    {
        $this->actingAsAdmin();

        $loan = Loan::factory()->create([
            'counterparty' => 'NORTH-EX',
            'original_amount' => 1000,
            'amount_eur' => 1000,
            'remaining_amount' => 1000,
        ]);
        $loan->repayments()->create([
            'amount' => 400, 'currency' => 'EUR', 'payment_date' => '2026-07-10', 'method' => 'cash',
        ]);
        $loan->recalculate();

        $rows = $this->getJson('/api/reports/loan_advance_balance_report')->assertOk()->json('data.rows');

        $this->assertSame('NORTH-EX', $rows[0]['counterparty']);
        $this->assertSame(400, $rows[0]['repaid_amount']);
        $this->assertSame(600, $rows[0]['remaining_amount']);
    }

    // ---- exports -------------------------------------------------------

    public function test_a_report_exports_to_excel(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create();
        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 250,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $invoice->recalculate();

        $response = $this->get('/api/reports/payables_aging/export?format=xlsx&title=Payables%20aging');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type'),
        );
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_a_report_exports_to_pdf_with_the_company_header(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Acme DOO']);
        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 250,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $invoice->recalculate();

        $response = $this->get('/api/reports/payables_aging/export?format=pdf&title=Payables%20aging');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $pdf = $response->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/payables_aging/export?format=docx')
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_an_unknown_report_is_a_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/not_a_report')->assertNotFound();
    }

    public function test_reports_require_the_view_permission_and_export_its_own(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['reports.view', 'payables.view']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/reports/payables_aging')->assertOk();
        // Viewing is not exporting.
        $this->getJson('/api/reports/payables_aging/export?format=xlsx')->assertForbidden();

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/reports')->assertForbidden();
    }

    public function test_a_bad_filter_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/monthly_cashflow?year=1800')
            ->assertStatus(422)
            ->assertJsonValidationErrors('year');
    }
}
