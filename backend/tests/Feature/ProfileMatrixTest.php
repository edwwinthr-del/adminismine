<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Worksite;
use App\Services\ApplyProfile;
use App\Support\CompanyConfig;
use App\Support\Industry\ProfileRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The same work, done under every profile.
 *
 * The claim this file exists to defend: **a profile changes what modules exist,
 * what things are called and how a working week is counted — never what a euro
 * is.** Every money path below is asserted to produce identical figures whatever
 * shape of company the install is set up as, and the one path that *should*
 * differ (a day's pay, because the divisor differs) is asserted to differ by
 * exactly the amount the working-day rule explains and no more.
 *
 * The plan called for two profiles here. All three are run instead: they are
 * core paths that do not depend on any module a profile removes, and the third
 * costs about a second.
 */
class ProfileMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array<string, array{0: string}> */
    public static function profiles(): array
    {
        return [
            'mining' => ['mining'],
            'construction' => ['construction'],
            'labour services' => ['labour_services'],
        ];
    }

    /** Working days in July 2026 under each profile's week. */
    private const WORKING_DAYS = [
        'mining' => 27,          // every day except Sunday
        'construction' => 23,    // Monday to Friday
        'labour_services' => 23,
    ];

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /** Set the install up, then act. Applied directly: the controller's refusal
     *  is about clicking a button on live data, which is not what this is. */
    private function setUpAs(string $profile): void
    {
        app(ApplyProfile::class)->apply(app(ProfileRegistry::class)->find($profile));
        app(CompanyConfig::class)->forget();
    }

    // ---- money -------------------------------------------------------------

    #[DataProvider('profiles')]
    public function test_a_payable_is_settled_identically_under_every_profile(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'original_amount' => 1000,
        ]);
        $invoice->recalculate();

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 400,
            'payment_date' => '2026-07-20',
            'account_id' => $this->cashAccount()->id,
            'book_bank_transaction' => true,
        ])->assertCreated();

        // Identical under every profile: what a company is called and which
        // modules it has do not touch arithmetic.
        $this->assertEqualsWithDelta(600, (float) $invoice->fresh()->remaining_amount, 0.01);
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertEqualsWithDelta(-400, $this->balanceOfAccount($this->cashAccount()), 0.01);
    }

    #[DataProvider('profiles')]
    public function test_a_receipt_reaches_the_dashboard_under_every_profile(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        $invoice = ReceivableInvoice::factory()->create(['invoice_amount' => 2500]);
        $invoice->recalculate();

        $this->postJson("/api/receivables/{$invoice->id}/payments", [
            'amount' => 2500,
            'payment_date' => now()->toDateString(),
            'account_id' => $this->nlbAccount()->id,
            'book_bank_transaction' => true,
        ])->assertCreated();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.balances.total', 2500)
            ->assertJsonPath('data.cashflow.month.income', 2500)
            ->assertJsonPath('data.receivables.outstanding', 0);
    }

    #[DataProvider('profiles')]
    public function test_a_foreign_currency_invoice_prices_identically_under_every_profile(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        ExchangeRate::create([
            'base_currency' => 'EUR', 'quote_currency' => 'TRY', 'rate' => 38.5, 'rate_date' => '2026-07-01',
        ]);

        $id = $this->postJson('/api/payables', [
            'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => '2026-07-15',
            'currency' => 'TRY',
            'original_amount' => 100000,
        ])->assertCreated()->json('data.id');

        // 100,000 TRY at 38.5 is 2,597.40 EUR in every industry.
        $invoice = PayableInvoice::find($id);
        $this->assertSame('100000.00', (string) $invoice->original_amount);
        $this->assertEqualsWithDelta(2597.4, (float) $invoice->amount_eur, 0.01);
        $this->assertEqualsWithDelta(38.5, (float) $invoice->exchange_rate, 0.001);
    }

    // ---- payroll -----------------------------------------------------------

    #[DataProvider('profiles')]
    public function test_a_day_of_work_is_priced_by_the_profiles_own_week(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        $row = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated()->json('data.0');

        $days = self::WORKING_DAYS[$profile];

        // The one figure that *should* move between profiles, and it moves by
        // exactly what the working-day rule explains: a five-day week has fewer
        // days to divide the month's salary across, so each is worth more.
        $this->assertSame($days, $row['working_days_basis']);
        $this->assertEqualsWithDelta(round(1350 / $days, 2), $row['daily_rate'], 0.01);
        $this->assertEqualsWithDelta(round(1350 / $days, 2), $row['regular_amount'], 0.01);
    }

    #[DataProvider('profiles')]
    public function test_overtime_follows_the_same_rules_under_every_profile(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        $row = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [[
                'employee_id' => $employee->id, 'status' => 'present',
                'regular_hours' => 8, 'overtime_hours' => 2,
            ]],
        ])->assertCreated()->json('data.0');

        // All three profiles keep the 8-hour day and the 1.5× multiplier, so the
        // only thing carrying through is the daily rate the week produced.
        $daily = round(1350 / self::WORKING_DAYS[$profile], 2);

        $this->assertEqualsWithDelta(($daily / 8) * 1.5 * 2, $row['overtime_amount'], 0.02);
    }

    // ---- the core survives every shape ------------------------------------

    #[DataProvider('profiles')]
    public function test_the_core_answers_under_every_profile(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        // Money in, money out, who did it — never switched off by anything.
        foreach ([
            '/api/payables', '/api/receivables', '/api/bank-transactions', '/api/bank-accounts',
            '/api/exchange-rates', '/api/dashboard', '/api/reports', '/api/audit-logs',
            '/api/notifications', '/api/imports/entities', '/api/company-settings',
        ] as $route) {
            $this->getJson($route)->assertOk();
        }
    }

    #[DataProvider('profiles')]
    public function test_every_profile_can_still_record_a_day_and_pay_for_it(string $profile): void
    {
        $this->actingAsAdmin();
        $this->setUpAs($profile);

        // Attendance, salaries, housing, travel and loans are what make this app
        // unlike a generic ERP; no profile drops them.
        foreach (['/api/employees', '/api/worksites', '/api/attendance?date=2026-07-15',
            '/api/salary-payments', '/api/houses', '/api/travel/tickets', '/api/loans'] as $route) {
            $this->getJson($route)->assertOk();
        }
    }

    // ---- the acceptance criterion for the whole project -------------------

    /**
     * The figures the app has always produced for one fixed piece of work: a
     * 1,000 EUR payable settled 400 in cash, and one 8-hour day with 2 hours of
     * overtime for a worker on 1,350 a month, in July 2026.
     *
     * Written out rather than compared between two runs, so that a change
     * affecting *both* an unconfigured install and a `mining` one still fails
     * here. These numbers are the app's behaviour, and this is where they are
     * pinned.
     */
    private const UNTOUCHED_BEHAVIOUR = [
        'remaining' => 600.0,
        'status' => 'partial',
        'cash_balance' => -400.0,
        // July 2026 has 27 days that are not Sundays.
        'working_days' => 27,
        'daily_rate' => 50.0,
        'regular' => 50.0,
        // 50.00 / 8 hours = 6.25, x 1.5 = 9.375, x 2 hours = 18.75.
        'overtime' => 18.75,
        'total' => 68.75,
        'dashboard_total' => -400.0,
        'dashboard_expenses' => 400.0,
    ];

    /** @return array<string, array{0: bool}> */
    public static function miningOrNothing(): array
    {
        return [
            'an install nobody configured' => [false],
            'an install set up as mining' => [true],
        ];
    }

    /**
     * **The acceptance criterion for the whole productization project.**
     *
     * `mining` plus no overrides must reproduce the app that existed before any
     * of this was built — not in its settings, which is easy, but in what it
     * *does*. The same work is run on an install nobody has configured and on
     * one set up as `mining`, and both are held against the same figures.
     */
    #[DataProvider('miningOrNothing')]
    public function test_mining_reproduces_the_untouched_app_behaviourally(bool $applyMining): void
    {
        $this->actingAsAdmin();

        if ($applyMining) {
            $this->setUpAs('mining');
        }

        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => Supplier::factory()->create(['name' => 'Acme DOO'])->id,
            'original_amount' => 1000,
        ]);
        $invoice->recalculate();

        $this->postJson("/api/payables/{$invoice->id}/payments", [
            'amount' => 400,
            'payment_date' => '2026-07-20',
            'account_id' => $this->cashAccount()->id,
            'book_bank_transaction' => true,
        ])->assertCreated();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        $day = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [[
                'employee_id' => $employee->id, 'status' => 'present',
                'regular_hours' => 8, 'overtime_hours' => 2,
            ]],
        ])->assertCreated()->json('data.0');

        // Asked for July, which is when the work happened. Balances are
        // all-time but the cashflow is a month, and reading it for "now" would
        // only assert that August has no July payments in it.
        $dashboard = $this->getJson('/api/dashboard?month=2026-07')->assertOk()->json('data');

        $produced = [
            'remaining' => round((float) $invoice->fresh()->remaining_amount, 2),
            'status' => $invoice->fresh()->status,
            'cash_balance' => $this->balanceOfAccount($this->cashAccount()),
            'working_days' => $day['working_days_basis'],
            'daily_rate' => round((float) $day['daily_rate'], 2),
            'regular' => round((float) $day['regular_amount'], 2),
            'overtime' => round((float) $day['overtime_amount'], 2),
            'total' => round((float) $day['total_amount'], 2),
            'dashboard_total' => round((float) $dashboard['balances']['total'], 2),
            'dashboard_expenses' => round((float) $dashboard['cashflow']['month']['expenses'], 2),
        ];

        $this->assertEquals(self::UNTOUCHED_BEHAVIOUR, $produced);

        // And every section a Super Admin has always seen is still there.
        foreach (['balances', 'cashflow', 'recent_transactions', 'payables', 'receivables', 'housing', 'alerts'] as $section) {
            $this->assertArrayHasKey($section, $dashboard, "the dashboard lost its {$section} section");
        }
    }
}
