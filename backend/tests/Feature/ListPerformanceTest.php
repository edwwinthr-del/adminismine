<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\CustomsDocument;
use App\Models\Employee;
use App\Models\FlightTicket;
use App\Models\Loan;
use App\Models\Machine;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\User;
use App\Models\WorkerNeed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The lists have to cost the same whether the company has ten records or ten
 * thousand.
 *
 * Each test runs one endpoint twice — once over a few rows, once over many —
 * and asserts the number of queries did not grow. That is what an N+1 is: a
 * query count that tracks the row count. Asserting on the shape rather than on
 * a fixed number means these keep working when a legitimate query is added.
 */
class ListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** Rows in the larger of the two runs. */
    private const MANY = 25;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);
    }

    /**
     * @param  callable(int): void  $seed  creates the given number of rows
     */
    private function assertQueryCountIsFlat(string $url, callable $seed): void
    {
        $seed(2);

        // The first request of a run also warms the permission cache, which
        // would otherwise show up as "queries that did not repeat".
        $this->getJson($url)->assertOk();

        $small = $this->countQueries($url);

        $seed(self::MANY - 2);
        $large = $this->countQueries($url);

        $this->assertSame(
            $small,
            $large,
            "GET {$url} ran {$small} queries for 2 rows and {$large} for ".self::MANY
            .' — the count grows with the data, which is an N+1.',
        );
    }

    private function countQueries(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_payables_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/payables?per_page=100',
            fn (int $count) => PayableInvoice::factory()->count($count)->create(),
        );
    }

    public function test_receivables_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/receivables?per_page=100',
            fn (int $count) => ReceivableInvoice::factory()->count($count)->create(),
        );
    }

    public function test_bank_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/bank-transactions?per_page=100',
            fn (int $count) => BankTransaction::factory()->count($count)->create(),
        );
    }

    public function test_employees_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/employees?per_page=100',
            fn (int $count) => Employee::factory()->count($count)->create(),
        );
    }

    public function test_machines_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/machines?per_page=100',
            fn (int $count) => Machine::factory()->count($count)->create(),
        );
    }

    public function test_customs_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/customs-documents?per_page=100',
            fn (int $count) => CustomsDocument::factory()->count($count)->create(),
        );
    }

    public function test_loans_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/loans',
            fn (int $count) => Loan::factory()->count($count)->create(),
        );
    }

    public function test_flight_tickets_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/travel/tickets',
            fn (int $count) => FlightTicket::factory()->count($count)->create(),
        );
    }

    public function test_worker_needs_list_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/worker-needs?per_page=100',
            fn (int $count) => WorkerNeed::factory()->count($count)->create(),
        );
    }

    /**
     * The dashboard is the one screen assembled from every module, so it is
     * where a per-row query would hurt most and be noticed least.
     */
    public function test_dashboard_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat('/api/dashboard', function (int $count): void {
            BankTransaction::factory()->count($count)->create();
            PayableInvoice::factory()->count($count)->create();
            ReceivableInvoice::factory()->count($count)->create();
        });
    }

    /** A lookup is opened on every form; it must never read more than a page. */
    public function test_a_lookup_does_not_query_per_row(): void
    {
        $this->assertQueryCountIsFlat(
            '/api/lookups/payable-invoices',
            fn (int $count) => PayableInvoice::factory()->count($count)->create(),
        );
    }
}
