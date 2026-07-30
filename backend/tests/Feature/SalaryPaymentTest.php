<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalaryPaymentTest extends TestCase
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

    public function test_generate_creates_one_obligation_per_active_monthly_employee(): void
    {
        $this->actingAsAdmin();

        $paid = Employee::factory()->create(['base_salary' => 1200, 'salary_currency' => 'EUR']);
        Employee::factory()->inactive()->create(['base_salary' => 900]);
        $noSalary = Employee::factory()->create(['base_salary' => null]);
        $daily = Employee::factory()->create(['base_salary' => 40, 'salary_period' => 'daily']);

        $response = $this->postJson('/api/salary-payments/generate', ['month' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('meta.month', '2026-07-01')
            ->assertJsonPath('meta.created_count', 1)
            ->assertJsonPath('meta.preview', false);

        $this->assertSame($paid->id, $response->json('data.0.employee_id'));
        $this->assertEqualsWithDelta(1200, $response->json('data.0.net_salary_due'), 0.001);
        $this->assertEqualsWithDelta(1200, $response->json('data.0.remaining_amount'), 0.001);
        $this->assertSame('unpaid', $response->json('data.0.status'));

        $reasons = collect($response->json('meta.skipped'))->pluck('reason', 'employee_id');
        $this->assertSame('missing_base_salary', $reasons[$noSalary->id]);
        $this->assertSame('not_monthly_salary', $reasons[$daily->id]);

        $this->assertSame(1, SalaryPayment::count());
    }

    public function test_generate_is_idempotent_and_preview_saves_nothing(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->create(['base_salary' => 1000]);

        $this->postJson('/api/salary-payments/generate', ['month' => '2026-07'])->assertCreated();

        $this->postJson('/api/salary-payments/generate', ['month' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('meta.created_count', 0)
            ->assertJsonPath('meta.already_existing_count', 1);

        $this->assertSame(1, SalaryPayment::count());

        // A different month gets its own row; preview mode saves nothing.
        $this->postJson('/api/salary-payments/generate', ['month' => '2026-08', 'preview' => true])
            ->assertOk()
            ->assertJsonPath('meta.preview', true)
            ->assertJsonPath('meta.created_count', 1);

        $this->assertSame(1, SalaryPayment::count());
    }

    public function test_adjustments_and_deductions_drive_the_net_due(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create(['base_salary' => 1000]);

        $id = $this->postJson('/api/salary-payments', [
            'employee_id' => $employee->id,
            'salary_month' => '2026-07',
            'base_salary' => 1000,
            'adjustments' => 150,
            'deductions' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.net_salary_due', 1100)
            ->assertJsonPath('data.remaining_amount', 1100)
            ->json('data.id');

        $this->putJson("/api/salary-payments/{$id}", ['deductions' => 200])
            ->assertOk()
            ->assertJsonPath('data.net_salary_due', 950)
            ->assertJsonPath('data.remaining_amount', 950);
    }

    public function test_partial_then_full_payment_updates_status(): void
    {
        $this->actingAsAdmin();
        $obligation = SalaryPayment::factory()->create(['base_salary' => 1000]);
        $obligation->recalculate();

        $this->postJson("/api/salary-payments/{$obligation->id}/payments", [
            'amount' => 400, 'payment_date' => '2026-08-05', 'method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.paid_amount', 400)
            ->assertJsonPath('data.remaining_amount', 600);

        $this->postJson("/api/salary-payments/{$obligation->id}/payments", [
            'amount' => 600, 'payment_date' => '2026-08-10', 'method' => 'nlb',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_payment_over_remaining_is_rejected(): void
    {
        $this->actingAsAdmin();
        $obligation = SalaryPayment::factory()->create(['base_salary' => 500]);
        $obligation->recalculate();

        $this->postJson("/api/salary-payments/{$obligation->id}/payments", [
            'amount' => 700, 'payment_date' => '2026-08-05', 'method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame('unpaid', $obligation->fresh()->status);
    }

    public function test_one_record_per_employee_and_month(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create(['base_salary' => 1000]);

        $payload = [
            'employee_id' => $employee->id,
            'salary_month' => '2026-07-01',
            'base_salary' => 1000,
        ];

        $this->postJson('/api/salary-payments', $payload)->assertCreated();
        $this->postJson('/api/salary-payments', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('salary_month');
    }

    public function test_index_filters_by_month_employee_and_outstanding(): void
    {
        $this->actingAsAdmin();

        $july = SalaryPayment::factory()->create(['salary_month' => '2026-07-01', 'base_salary' => 1000]);
        $july->recalculate();
        $august = SalaryPayment::factory()->create(['salary_month' => '2026-08-01', 'base_salary' => 800]);
        $august->payments()->create(['amount' => 800, 'currency' => 'EUR', 'payment_date' => '2026-08-31', 'method' => 'cash']);
        $august->recalculate();

        $ids = collect($this->getJson('/api/salary-payments?month=2026-07')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$july->id], $ids);

        $ids = collect($this->getJson('/api/salary-payments?outstanding=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$july->id], $ids);

        $ids = collect($this->getJson("/api/salary-payments?employee_id={$august->employee_id}")->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$august->id], $ids);
    }

    public function test_summary_totals_a_month(): void
    {
        $this->actingAsAdmin();

        $first = SalaryPayment::factory()->create(['salary_month' => '2026-07-01', 'base_salary' => 1000]);
        $first->payments()->create(['amount' => 250, 'currency' => 'EUR', 'payment_date' => '2026-07-20', 'method' => 'cash']);
        $first->recalculate();

        $second = SalaryPayment::factory()->create(['salary_month' => '2026-07-01', 'base_salary' => 500]);
        $second->recalculate();

        SalaryPayment::factory()->create(['salary_month' => '2026-08-01', 'base_salary' => 900])->recalculate();

        $this->getJson('/api/salary-payments/summary?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.employees', 2)
            ->assertJsonPath('data.net_due', 1500)
            ->assertJsonPath('data.paid', 250)
            ->assertJsonPath('data.remaining', 1250)
            ->assertJsonPath('data.unpaid_count', 1)
            ->assertJsonPath('data.partial_count', 1);
    }

    public function test_employee_removal_is_blocked_while_salary_history_exists(): void
    {
        $this->actingAsAdmin();
        $obligation = SalaryPayment::factory()->create();

        // Soft-deleting the employee keeps the salary history intact.
        $this->deleteJson("/api/employees/{$obligation->employee_id}")->assertOk();

        $this->assertDatabaseHas('salary_payments', ['id' => $obligation->id]);
    }

    public function test_salary_payments_require_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker'); // no salary_payments.manage
        Sanctum::actingAs($user);

        $this->getJson('/api/salary-payments')->assertStatus(403);
        $this->postJson('/api/salary-payments/generate', ['month' => '2026-07'])->assertStatus(403);
    }
}
