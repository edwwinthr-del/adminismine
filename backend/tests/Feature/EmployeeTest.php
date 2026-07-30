<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeTest extends TestCase
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

    public function test_create_employee_returns_full_name_and_defaults(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/employees', [
            'first_name' => 'Mehmet',
            'last_name' => 'Yilmaz',
            'origin_country' => 'TR',
            'passport_number' => 'U1234567',
            'base_salary' => 1200,
        ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Mehmet Yilmaz')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.salary_currency', 'EUR')
            ->assertJsonPath('data.salary_period', 'monthly')
            ->assertJsonPath('data.salary_calculation_rule', 'working_days')
            ->assertJsonPath('data.bank_account_status', 'unknown')
            ->assertJsonPath('data.base_salary', 1200);
    }

    public function test_audit_columns_are_stamped(): void
    {
        $user = $this->actingAsAdmin();

        $id = $this->postJson('/api/employees', [
            'first_name' => 'Ana', 'last_name' => 'Petrovic',
        ])->assertCreated()->json('data.id');

        $this->assertSame($user->id, Employee::find($id)->created_by);
    }

    public function test_invalid_enum_values_are_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/employees', [
            'first_name' => 'Ivan',
            'last_name' => 'Ivanov',
            'bank_account_status' => 'ODENDI', // an imported label, not a canonical value
        ])->assertStatus(422)->assertJsonValidationErrors('bank_account_status');
    }

    public function test_missing_documents_are_reported_and_filterable(): void
    {
        $this->actingAsAdmin();

        $complete = Employee::factory()->create();
        $incomplete = Employee::factory()->create([
            'passport_number' => null,
            'medical_exam_expiry' => null,
        ]);

        $this->getJson("/api/employees/{$incomplete->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_documents', ['passport_number', 'medical_exam_expiry']);

        $this->getJson("/api/employees/{$complete->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_documents', []);

        $ids = collect($this->getJson('/api/employees?missing_documents=1')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertSame([$incomplete->id], $ids);
    }

    public function test_document_alerts_flag_expired_and_soon_expiring(): void
    {
        $this->actingAsAdmin();

        $employee = Employee::factory()->create([
            'work_permit_expiry' => now()->subDays(5)->toDateString(),
            'medical_exam_expiry' => now()->addDays(20)->toDateString(),
            'residence_permit_expiry' => now()->addYears(2)->toDateString(),
            'safety_training_expiry' => now()->addYears(2)->toDateString(),
            'contract_end_date' => now()->addYears(2)->toDateString(),
        ]);

        $alerts = $this->getJson("/api/employees/{$employee->id}")->assertOk()->json('data.document_alerts');

        $this->assertCount(2, $alerts);
        $this->assertSame('work_permit_expiry', $alerts[0]['document']);
        $this->assertTrue($alerts[0]['expired']);
        $this->assertSame(-5, $alerts[0]['days_remaining']);
        $this->assertSame('medical_exam_expiry', $alerts[1]['document']);
        $this->assertFalse($alerts[1]['expired']);
        $this->assertSame(20, $alerts[1]['days_remaining']);
    }

    public function test_expiring_documents_report_lists_only_active_employees_in_window(): void
    {
        $this->actingAsAdmin();

        $soon = Employee::factory()->create(['work_permit_expiry' => now()->addDays(10)->toDateString()]);
        Employee::factory()->create(['work_permit_expiry' => now()->addDays(200)->toDateString()]);
        Employee::factory()->inactive()->create(['work_permit_expiry' => now()->addDays(3)->toDateString()]);

        $response = $this->getJson('/api/employees/expiring-documents?within_days=30')->assertOk();

        $this->assertSame([$soon->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(30, $response->json('meta.within_days'));
    }

    public function test_index_filters_by_status_and_search(): void
    {
        $this->actingAsAdmin();

        $active = Employee::factory()->create(['first_name' => 'Selim', 'last_name' => 'Kaya']);
        Employee::factory()->inactive()->create(['first_name' => 'Emir', 'last_name' => 'Demir']);

        $ids = collect($this->getJson('/api/employees?status=active')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$active->id], $ids);

        $ids = collect($this->getJson('/api/employees?search=Kaya')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$active->id], $ids);
    }

    public function test_removal_deactivates_and_keeps_the_record(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        $this->deleteJson("/api/employees/{$employee->id}")->assertOk();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertSame('inactive', Employee::withTrashed()->find($employee->id)->status);

        // Hidden from the default list, visible with with_removed.
        $this->assertSame([], $this->getJson('/api/employees')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/employees?with_removed=1')->assertOk()->json('data'));

        $this->postJson("/api/employees/{$employee->id}/restore")->assertOk();
        $this->assertNull(Employee::find($employee->id)->deleted_at);
    }

    public function test_daily_rate_follows_the_salary_rule(): void
    {
        $monthly = Employee::factory()->make(['base_salary' => 1300, 'salary_period' => 'monthly']);
        $this->assertSame(50.0, $monthly->dailyRate(26));

        $fixed = Employee::factory()->make([
            'salary_calculation_rule' => 'fixed_daily',
            'daily_rate_override' => 45.5,
        ]);
        $this->assertSame(45.5, $fixed->dailyRate(26));

        $unset = Employee::factory()->make(['base_salary' => null]);
        $this->assertNull($unset->dailyRate(26));
    }

    public function test_employees_require_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker'); // no employees.manage
        Sanctum::actingAs($user);

        $this->getJson('/api/employees')->assertStatus(403);
        $this->postJson('/api/employees', ['first_name' => 'A', 'last_name' => 'B'])->assertStatus(403);
    }
}
