<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Master;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Models\Worksite;
use App\Services\WorkingDaysService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceTest extends TestCase
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

    /** A master with only attendance.submit: may enter days, may not approve them. */
    private function actingAsMaster(Worksite ...$worksites): Master
    {
        $user = User::factory()->create();
        $user->givePermissionTo('attendance.submit');

        $master = Master::factory()->create(['user_id' => $user->id]);
        $master->worksites()->sync(collect($worksites)->pluck('id')->all());

        Sanctum::actingAs($user);

        return $master;
    }

    public function test_roster_lists_assigned_employees_with_their_daily_rate(): void
    {
        $this->actingAsAdmin();

        // July 2026 has 27 non-Sunday days.
        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);
        Employee::factory()->create(); // not assigned to the site

        $response = $this->getJson("/api/attendance/roster?worksite_id={$worksite->id}&date=2026-07-15")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.record', null);

        $workingDays = app(WorkingDaysService::class)->derivedForMonth('2026-07');
        $this->assertSame(27, $workingDays);
        $this->assertEqualsWithDelta(round(1350 / 27, 2), $response->json('data.0.daily_rate'), 0.001);
        $this->assertSame(27, $response->json('meta.working_days_basis'));
    }

    public function test_day_entry_computes_regular_and_overtime_pay(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]); // 50.00 / day in July 2026
        $halfDay = Employee::factory()->create(['base_salary' => 1350]);
        $absent = Employee::factory()->create(['base_salary' => 1350]);
        $holiday = Employee::factory()->create(['base_salary' => 1350]);

        $response = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [
                ['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8, 'overtime_hours' => 2],
                ['employee_id' => $halfDay->id, 'status' => 'present', 'regular_hours' => 4],
                ['employee_id' => $absent->id, 'status' => 'absent'],
                ['employee_id' => $holiday->id, 'status' => 'holiday'],
            ],
        ])->assertCreated();

        $rows = collect($response->json('data'))->keyBy('employee_id');

        // 50.00 base + 2 h at 50/8 × 1.5 = 18.75 → 68.75
        $this->assertEqualsWithDelta(50.0, $rows[$employee->id]['regular_amount'], 0.01);
        $this->assertEqualsWithDelta(18.75, $rows[$employee->id]['overtime_amount'], 0.01);
        $this->assertEqualsWithDelta(68.75, $rows[$employee->id]['total_amount'], 0.01);
        $this->assertSame(27, $rows[$employee->id]['working_days_basis']);

        $this->assertEqualsWithDelta(25.0, $rows[$halfDay->id]['regular_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $rows[$absent->id]['total_amount'], 0.01);
        $this->assertEqualsWithDelta(50.0, $rows[$holiday->id]['regular_amount'], 0.01);

        // Every row starts as draft: nothing reaches payroll before approval.
        $this->assertSame(['draft'], collect($response->json('data'))->pluck('approval_status')->unique()->all());
        $this->assertFalse($rows[$employee->id]['approved_for_payroll']);
    }

    public function test_posting_the_same_day_twice_updates_instead_of_duplicating(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);

        $payload = fn (string $status, ?float $hours) => [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => $status, 'regular_hours' => $hours]],
        ];

        $this->postJson('/api/attendance', $payload('present', 8))->assertCreated();
        $this->postJson('/api/attendance', $payload('absent', null))->assertCreated();

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('absent', AttendanceRecord::first()->status);
        $this->assertEqualsWithDelta(0.0, (float) AttendanceRecord::first()->total_amount, 0.01);
    }

    public function test_employee_may_appear_only_once_per_request(): void
    {
        $this->actingAsAdmin();
        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create();

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [
                ['employee_id' => $employee->id, 'status' => 'present'],
                ['employee_id' => $employee->id, 'status' => 'absent'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('records.1.employee_id');
    }

    public function test_submit_then_approve_releases_the_day_to_payroll(): void
    {
        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        // The master enters and submits the day.
        $this->actingAsMaster($worksite);
        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated();

        $this->postJson('/api/attendance/submit', ['date' => '2026-07-15', 'worksite_id' => $worksite->id])
            ->assertOk()
            ->assertJsonPath('meta.count', 1);

        $record = AttendanceRecord::first();
        $this->assertSame('submitted', $record->approval_status);
        $this->assertNotNull($record->submitted_at);
        $this->assertSame($record->master_id, Master::first()->id);

        // A master cannot approve their own day.
        $this->postJson('/api/attendance/approve', ['date' => '2026-07-15', 'worksite_id' => $worksite->id])
            ->assertStatus(403);

        // The office approves it.
        $office = $this->actingAsAdmin();
        $this->postJson('/api/attendance/approve', ['date' => '2026-07-15', 'worksite_id' => $worksite->id])
            ->assertOk()
            ->assertJsonPath('meta.count', 1);

        $record->refresh();
        $this->assertSame('approved', $record->approval_status);
        $this->assertTrue($record->approved_for_payroll);
        $this->assertSame($office->id, $record->approved_by);
    }

    public function test_approved_day_is_locked_for_the_master_but_editable_by_the_office(): void
    {
        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $record = AttendanceRecord::factory()->approved()->create([
            'worksite_id' => $worksite->id,
            'employee_id' => $employee->id,
            'date' => '2026-07-15',
        ]);

        $this->actingAsMaster($worksite);
        $this->putJson("/api/attendance/{$record->id}", ['status' => 'absent'])->assertStatus(422);
        $this->deleteJson("/api/attendance/{$record->id}")->assertStatus(422);

        // Re-posting the day skips the locked row instead of overwriting it.
        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'absent']],
        ])
            ->assertCreated()
            ->assertJsonPath('meta.locked_employee_ids', [$employee->id]);

        $this->assertSame('present', $record->fresh()->status);

        $this->actingAsAdmin();
        $this->putJson("/api/attendance/{$record->id}", ['status' => 'absent'])->assertOk();
        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_rejecting_a_day_records_the_reason_and_pulls_it_from_payroll(): void
    {
        $this->actingAsAdmin();
        $record = AttendanceRecord::factory()->approved()->create(['date' => '2026-07-15']);

        $this->postJson('/api/attendance/reject', [
            'date' => '2026-07-15',
            'worksite_id' => $record->worksite_id,
            'reason' => 'Hours do not match the site log',
        ])->assertOk();

        $record->refresh();
        $this->assertSame('rejected', $record->approval_status);
        $this->assertFalse($record->approved_for_payroll);
        $this->assertSame('Hours do not match the site log', $record->rejection_reason);

        $this->postJson('/api/attendance/reject', [
            'date' => '2026-07-15',
            'worksite_id' => $record->worksite_id,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_master_cannot_touch_a_worksite_they_are_not_assigned_to(): void
    {
        $mine = Worksite::factory()->create();
        $other = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        AttendanceRecord::factory()->create(['worksite_id' => $other->id, 'date' => '2026-07-15']);

        $this->actingAsMaster($mine);

        $this->getJson("/api/attendance/roster?worksite_id={$other->id}&date=2026-07-15")->assertStatus(403);

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $other->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present']],
        ])->assertStatus(403);

        // The other site's records are invisible in the list, too.
        $this->assertSame([], $this->getJson('/api/attendance')->assertOk()->json('data'));
    }

    public function test_adjustment_requires_a_reason_and_changes_the_total(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);

        $id = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated()->json('data.0.id');

        $this->postJson("/api/attendance/{$id}/adjust", ['adjustment_amount' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('adjustment_reason');

        $this->postJson("/api/attendance/{$id}/adjust", [
            'adjustment_amount' => -5.5,
            'adjustment_reason' => 'Site canteen deduction',
        ])
            ->assertOk()
            ->assertJsonPath('data.adjustment_reason', 'Site canteen deduction');

        $this->assertEqualsWithDelta(44.5, (float) AttendanceRecord::find($id)->total_amount, 0.01);
    }

    public function test_working_days_override_changes_the_daily_rate(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/working-days?month=2026-07')
            ->assertOk()
            ->assertJsonPath('data.working_days', 27)
            ->assertJsonPath('data.is_overridden', false);

        $this->putJson('/api/working-days', [
            'month' => '2026-07',
            'working_days' => 25,
            'reason' => 'Two site shutdown days',
        ])->assertOk()->assertJsonPath('data.working_days', 25);

        $this->putJson('/api/working-days', ['month' => '2026-07', 'working_days' => 25])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1000]);

        $row = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated()->json('data.0');

        $this->assertSame(25, $row['working_days_basis']);
        $this->assertEqualsWithDelta(40.0, $row['regular_amount'], 0.01); // 1000 / 25
    }

    public function test_fixed_daily_and_hourly_overtime_settings_are_honoured(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create([
            'salary_calculation_rule' => 'fixed_daily',
            'daily_rate_override' => 60,
            'overtime_hourly_rate' => 12,
        ]);

        $row = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8, 'overtime_hours' => 3]],
        ])->assertCreated()->json('data.0');

        $this->assertEqualsWithDelta(60.0, $row['regular_amount'], 0.01);
        $this->assertEqualsWithDelta(36.0, $row['overtime_amount'], 0.01);
        $this->assertEqualsWithDelta(96.0, $row['total_amount'], 0.01);
    }

    public function test_monthly_summary_aggregates_days_and_earnings(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8, 'overtime_hours' => 2]],
        ])->assertCreated();

        $this->postJson('/api/attendance', [
            'date' => '2026-07-16',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'absent']],
        ])->assertCreated();

        $response = $this->getJson('/api/attendance/summary?month=2026-07')->assertOk();

        $this->assertSame(1, $response->json('data.0.days_present'));
        $this->assertSame(1, $response->json('data.0.days_absent'));
        $this->assertEqualsWithDelta(2, $response->json('data.0.overtime_hours'), 0.01);
        $this->assertEqualsWithDelta(68.75, $response->json('data.0.total_earned'), 0.01);
        $this->assertSame(2, $response->json('data.0.pending_days'));
        $this->assertSame(27, $response->json('meta.working_days_basis'));
    }

    public function test_payroll_preparation_compares_approved_pay_with_the_salary_obligation(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated();

        // Unapproved days do not count towards payroll.
        $this->getJson('/api/attendance/payroll-preparation?month=2026-07')
            ->assertOk()
            ->assertJsonPath('meta.earned_total', 0);

        $this->postJson('/api/attendance/approve', ['date' => '2026-07-15', 'worksite_id' => $worksite->id])
            ->assertOk();

        SalaryPayment::factory()->create([
            'employee_id' => $employee->id,
            'salary_month' => '2026-07-01',
            'base_salary' => 1350,
        ])->recalculate();

        $response = $this->getJson('/api/attendance/payroll-preparation?month=2026-07')->assertOk();

        $this->assertSame(1, $response->json('data.0.approved_days'));
        $this->assertEqualsWithDelta(50.0, $response->json('data.0.earned_from_attendance'), 0.01);
        $this->assertEqualsWithDelta(1350.0, $response->json('data.0.net_salary_due'), 0.01);
        $this->assertEqualsWithDelta(-1300.0, $response->json('data.0.difference'), 0.01);
    }

    public function test_attendance_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->getJson('/api/attendance')->assertStatus(403);
        $this->postJson('/api/attendance', [])->assertStatus(403);
    }

    /**
     * The status check lived in the regular-pay branch only, so a day that paid
     * nothing still paid its overtime hours — a worker recorded as absent earned.
     */
    public function test_a_day_that_earns_nothing_earns_no_overtime_either(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $absent = Employee::factory()->create(['base_salary' => 1350]);
        $present = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach([$absent->id, $present->id]);

        $this->postJson('/api/attendance', [
            'worksite_id' => $worksite->id,
            'date' => '2026-07-15',
            'records' => [
                ['employee_id' => $absent->id, 'status' => 'absent', 'regular_hours' => 0, 'overtime_hours' => 4],
                ['employee_id' => $present->id, 'status' => 'present', 'regular_hours' => 8, 'overtime_hours' => 4],
            ],
        ])->assertCreated();

        $absentRecord = AttendanceRecord::where('employee_id', $absent->id)->sole();
        $this->assertSame(0.0, (float) $absentRecord->overtime_amount);
        $this->assertSame(0.0, (float) $absentRecord->total_amount);

        // A day that does earn is untouched: 50.00 + 4h at 50/8 × 1.5.
        $presentRecord = AttendanceRecord::where('employee_id', $present->id)->sole();
        $this->assertSame(37.5, (float) $presentRecord->overtime_amount);
        $this->assertSame(87.5, (float) $presentRecord->total_amount);
    }

    /**
     * The earned figures are cached on the record, and overriding a month's
     * working days changes the divisor they were built from. Days already
     * entered kept the old rate, so what a worker earned depended on whether the
     * office typed their day before or after the override.
     */
    public function test_overriding_a_months_working_days_rebuilds_the_days_already_entered(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        $this->postJson('/api/attendance', [
            'worksite_id' => $worksite->id,
            'date' => '2026-07-15',
            'records' => [
                ['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8],
            ],
        ])->assertCreated();

        // July 2026 derives 27 non-Sunday days: 1350 / 27 = 50.00.
        $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
        $this->assertSame(27, $record->working_days_basis);
        $this->assertSame(50.0, (float) $record->total_amount);

        $this->putJson('/api/working-days', [
            'month' => '2026-07',
            'working_days' => 25,
            'reason' => 'Two public holidays',
        ])->assertOk()->assertJsonPath('data.recomputed_records', 1);

        // 1350 / 25 = 54.00, for the day that was already on the books.
        $record->refresh();
        $this->assertSame(25, $record->working_days_basis);
        $this->assertSame(54.0, (float) $record->daily_rate);
        $this->assertSame(54.0, (float) $record->total_amount);

        // Removing the override puts the month back where it was.
        $this->deleteJson('/api/working-days?month=2026-07')->assertOk();
        $record->refresh();
        $this->assertSame(27, $record->working_days_basis);
        $this->assertSame(50.0, (float) $record->total_amount);
    }
}
