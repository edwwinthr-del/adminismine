<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\CompanySettings;
use App\Models\Employee;
use App\Models\SocialAssistancePayment;
use App\Models\User;
use App\Models\Worksite;
use App\Services\WorkingDaysService;
use App\Support\CompanyConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The rules the spec left open, now the company's to answer.
 *
 * A standard day of 8 hours, overtime at 1.5×, a six-day working week and 1,000
 * EUR of social assistance a year were constants in three services — so a
 * company on a five-day week got a divisor that was simply wrong, and every
 * daily rate with it.
 *
 * The first test is the important one: the defaults reproduce exactly what the
 * app did before any of this existed. The rest prove the values actually move.
 */
class CompanyPayrollRulesTest extends TestCase
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

    private function config(): CompanyConfig
    {
        return app(CompanyConfig::class);
    }

    /** A worked day of the given hours, for a worker on 1,350 a month. */
    private function dayOf(float $hours, float $overtime = 0): AttendanceRecord
    {
        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [[
                'employee_id' => $employee->id,
                'status' => 'present',
                'regular_hours' => $hours,
                'overtime_hours' => $overtime,
            ]],
        ])->assertCreated();

        return AttendanceRecord::sole();
    }

    public function test_the_defaults_are_what_the_app_always_did(): void
    {
        // The whole point of the fallbacks: an install migrated but never
        // configured is unchanged.
        $this->assertSame(8.0, $this->config()->standardDayHours());
        $this->assertSame(1.5, $this->config()->overtimeMultiplier());
        $this->assertSame('every_non_sunday', $this->config()->workingDayRule());
        $this->assertSame(1000.0, $this->config()->socialAssistanceAnnual());

        // July 2026: 27 days that are not Sundays.
        $this->assertSame(27, app(WorkingDaysService::class)->derivedForMonth('2026-07'));
    }

    public function test_a_shorter_standard_day_prorates_differently(): void
    {
        $this->actingAsAdmin();

        // 1350 / 27 working days = 50.00 a day. Six hours of an eight-hour day
        // is three quarters of it.
        $this->assertEqualsWithDelta(37.5, (float) $this->dayOf(6)->regular_amount, 0.01);

        AttendanceRecord::query()->delete();
        CompanySettings::current()->update(['standard_day_hours' => 6]);

        // Six hours is now the whole day.
        $this->assertEqualsWithDelta(50.0, (float) $this->dayOf(6)->regular_amount, 0.01);
    }

    public function test_the_overtime_multiplier_is_the_companys(): void
    {
        $this->actingAsAdmin();

        // 50.00 / 8 hours = 6.25 an hour, × 1.5 = 9.375, × 2 hours = 18.75.
        $this->assertEqualsWithDelta(18.75, (float) $this->dayOf(8, 2)->overtime_amount, 0.01);

        AttendanceRecord::query()->delete();
        CompanySettings::current()->update(['overtime_multiplier' => 2]);

        // 6.25 × 2 = 12.50 an hour, × 2 hours = 25.00.
        $this->assertEqualsWithDelta(25.0, (float) $this->dayOf(8, 2)->overtime_amount, 0.01);
    }

    public function test_the_working_day_rule_changes_the_divisor(): void
    {
        $days = fn (): int => app()->make(WorkingDaysService::class)->derivedForMonth('2026-07');

        $this->assertSame(27, $days());

        CompanySettings::current()->update(['working_day_rule' => 'mon_fri']);
        $this->assertSame(23, $days());

        CompanySettings::current()->update(['working_day_rule' => 'calendar']);
        $this->assertSame(31, $days());
    }

    public function test_the_social_assistance_entitlement_is_the_companys(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->create();

        SocialAssistancePayment::factory()->create([
            'employee_id' => $employee->id,
            'payment_date' => '2026-03-01',
            'entitlement_year' => 2026,
            'amount' => 400,
        ]);

        $this->getJson('/api/travel/social-assistance/summary?year=2026')
            ->assertOk()
            ->assertJsonPath('data.entitlement', 1000);

        CompanySettings::current()->update(['social_assistance_annual' => 1500]);

        $this->getJson('/api/travel/social-assistance/summary?year=2026')
            ->assertOk()
            ->assertJsonPath('data.entitlement', 1500);
    }

    public function test_changing_a_payroll_rule_recomputes_the_days_already_entered(): void
    {
        $this->actingAsAdmin();
        $record = $this->dayOf(6);

        $this->assertEqualsWithDelta(37.5, (float) $record->regular_amount, 0.01);

        // A cached figure whose input has moved is not a cache but a wrong
        // number: without this, two workers with identical attendance would be
        // paid differently depending on which side of the change their day was
        // typed.
        $this->putJson('/api/company-settings', ['standard_day_hours' => 6])
            ->assertOk()
            ->assertJsonPath('attendance_recomputed', 1);

        $this->assertEqualsWithDelta(50.0, (float) $record->fresh()->regular_amount, 0.01);
    }

    public function test_an_unrelated_setting_leaves_payroll_alone(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(6);

        // Renaming the company is not a payroll event.
        $this->putJson('/api/company-settings', ['company_name' => 'Something Else DOO'])
            ->assertOk()
            ->assertJsonPath('attendance_recomputed', 0)
            // Nothing moved, so there is nothing to report moving.
            ->assertJsonPath('earned_pay', null);
    }

    public function test_a_rule_change_reports_what_it_did_to_the_wage_bill(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(6);

        // The count says something happened; the totals say what. 6 hours of a
        // 50/day rate on an 8-hour standard day earns 37.50; on a 6-hour day the
        // same attendance is a full day and earns 50.
        $response = $this->putJson('/api/company-settings', ['standard_day_hours' => 6])
            ->assertOk()
            ->assertJsonPath('attendance_recomputed', 1);

        $this->assertEqualsWithDelta(37.5, $response->json('earned_pay.before.EUR'), 0.01);
        $this->assertEqualsWithDelta(50.0, $response->json('earned_pay.after.EUR'), 0.01);
    }

    public function test_the_wage_bill_is_reported_per_currency_never_summed_across_it(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(6);

        // Attendance stores `currency` and `total_amount` with no EUR twin, so
        // one total over the table would add TRY face values onto EUR ones —
        // the cross-record bug the money columns exist to prevent (rule 5).
        $record = AttendanceRecord::query()->firstOrFail();
        $record->employee->update(['salary_currency' => 'TRY']);
        $record->forceFill(['currency' => 'TRY'])->save();

        $response = $this->putJson('/api/company-settings', ['standard_day_hours' => 6])->assertOk();

        $this->assertNull($response->json('earned_pay.after.EUR'), 'A TRY day was counted as EUR.');
        $this->assertNotNull($response->json('earned_pay.after.TRY'));
    }

    public function test_two_currencies_are_reported_side_by_side(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(6);

        // A second worker paid in TRY, on the same day at the same site.
        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350, 'salary_currency' => 'TRY']);
        $worksite->employees()->attach($employee->id);

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [[
                'employee_id' => $employee->id,
                'status' => 'present',
                'regular_hours' => 6,
                'overtime_hours' => 0,
            ]],
        ])->assertCreated();

        $response = $this->putJson('/api/company-settings', ['standard_day_hours' => 6])->assertOk();

        // Two figures, side by side. One combined number would be a lie in both
        // currencies — there is no rate on an attendance row to make it true.
        $this->assertEqualsWithDelta(37.5, $response->json('earned_pay.before.EUR'), 0.01);
        $this->assertEqualsWithDelta(37.5, $response->json('earned_pay.before.TRY'), 0.01);
        $this->assertEqualsWithDelta(50.0, $response->json('earned_pay.after.EUR'), 0.01);
        $this->assertEqualsWithDelta(50.0, $response->json('earned_pay.after.TRY'), 0.01);
    }

    public function test_a_rule_change_that_lowers_pay_reports_the_fall(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(8);

        // A full 8-hour day earns the whole daily rate. Stretch the standard day
        // to 10 and the same attendance is now four-fifths of one.
        $response = $this->putJson('/api/company-settings', ['standard_day_hours' => 10])->assertOk();

        $before = $response->json('earned_pay.before.EUR');
        $after = $response->json('earned_pay.after.EUR');

        $this->assertEqualsWithDelta(50.0, $before, 0.01);
        $this->assertEqualsWithDelta(40.0, $after, 0.01);
        $this->assertLessThan($before, $after, 'A rule that cuts pay was reported as a rise.');
    }

    public function test_an_install_with_no_attendance_reports_no_movement(): void
    {
        $this->actingAsAdmin();

        // Nothing entered yet: the rule still changes, there is just nothing for
        // it to have moved.
        $response = $this->putJson('/api/company-settings', ['standard_day_hours' => 6])
            ->assertOk()
            ->assertJsonPath('attendance_recomputed', 0);

        $this->assertSame([], $response->json('earned_pay.before'));
        $this->assertSame([], $response->json('earned_pay.after'));
    }

    public function test_the_wage_bill_movement_is_recorded_in_the_audit_entry(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(6);

        $this->putJson('/api/company-settings', ['standard_day_hours' => 6])->assertOk();

        $entry = ActivityLog::query()->where('description', 'company_settings.updated')->latest('id')->first();

        // A settings edit that moved the wage bill should say by how much in the
        // trail, not only in the response the operator saw once (rule 3).
        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(37.5, $entry->properties['earned_pay']['before']['EUR'], 0.01);
        $this->assertEqualsWithDelta(50.0, $entry->properties['earned_pay']['after']['EUR'], 0.01);
    }

    public function test_an_unrelated_setting_writes_no_wage_bill_to_the_trail(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(6);

        $this->putJson('/api/company-settings', ['company_name' => 'Something Else DOO'])->assertOk();

        $entry = ActivityLog::query()->where('description', 'company_settings.updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('earned_pay', $entry->properties->toArray());
    }

    public function test_a_rule_that_would_break_the_arithmetic_is_refused(): void
    {
        $this->actingAsAdmin();

        // A zero-hour standard day divides by nothing; the rest are divisors and
        // multipliers behind every earned figure in the app.
        $this->putJson('/api/company-settings', ['standard_day_hours' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('standard_day_hours');

        $this->putJson('/api/company-settings', ['overtime_multiplier' => 0.5])
            ->assertStatus(422)->assertJsonValidationErrors('overtime_multiplier');

        $this->putJson('/api/company-settings', ['working_day_rule' => 'whenever'])
            ->assertStatus(422)->assertJsonValidationErrors('working_day_rule');
    }

    public function test_changing_the_rules_needs_the_settings_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        $this->putJson('/api/company-settings', ['standard_day_hours' => 6])->assertStatus(403);
    }

    public function test_the_change_is_audited_with_what_it_moved(): void
    {
        $this->actingAsAdmin();
        $this->dayOf(8);

        $this->putJson('/api/company-settings', ['overtime_multiplier' => 2])->assertOk();

        $entry = ActivityLog::query()->where('description', 'company_settings.updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(1, $entry->properties['attendance_recomputed']);
        $this->assertArrayHasKey('overtime_multiplier', $entry->properties['changes']);
    }
}
