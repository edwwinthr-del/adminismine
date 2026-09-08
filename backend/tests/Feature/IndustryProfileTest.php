<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\CompanySettings;
use App\Models\Employee;
use App\Models\Mine;
use App\Models\User;
use App\Models\Worksite;
use App\Services\WorkingDaysService;
use App\Support\CompanyConfig;
use App\Support\Industry\ProfileRegistry;
use App\Support\Modules;
use App\Support\Terminology;
use App\Support\Vocabulary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setting an install up as one shape of company.
 *
 * The first test is the one that matters: **`mining` reproduces the app's own
 * defaults exactly.** Everything else in this file is measured against that —
 * it is what makes "did a profile change something it should not have" a
 * question with an answer.
 */
class IndustryProfileTest extends TestCase
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

    private function applyProfile(string $key, bool $force = false)
    {
        return $this->postJson('/api/company-settings/profile', array_filter([
            'profile' => $key,
            'force' => $force ?: null,
        ]));
    }

    public function test_mining_is_the_apps_own_defaults_restated(): void
    {
        $this->actingAsAdmin();

        // Everything the app does before any profile is applied.
        $before = [
            'modules' => $this->config()->enabledModules(),
            'levels' => $this->config()->workStructureLevels(),
            'day' => $this->config()->standardDayHours(),
            'multiplier' => $this->config()->overtimeMultiplier(),
            'rule' => $this->config()->workingDayRule(),
            'entitlement' => $this->config()->socialAssistanceAnnual(),
            'materials' => Vocabulary::values('material_type'),
            'terminology' => Terminology::all(),
        ];

        $this->applyProfile('mining')->assertOk();
        $this->config()->forget();

        $this->assertSame($before['modules'], $this->config()->enabledModules());
        $this->assertSame($before['levels'], $this->config()->workStructureLevels());
        $this->assertSame($before['day'], $this->config()->standardDayHours());
        $this->assertSame($before['multiplier'], $this->config()->overtimeMultiplier());
        $this->assertSame($before['rule'], $this->config()->workingDayRule());
        $this->assertSame($before['entitlement'], $this->config()->socialAssistanceAnnual());
        $this->assertSame($before['materials'], Vocabulary::values('material_type'));
        $this->assertSame($before['terminology'], Terminology::all());
    }

    public function test_construction_drops_the_deposit_and_the_border(): void
    {
        $this->actingAsAdmin();
        $this->applyProfile('construction')->assertOk();
        $this->config()->forget();

        // No customs: machines are not crossing a border under a CMR.
        $this->assertNotContains('customs', $this->config()->enabledModules());
        $this->getJson('/api/customs-documents')->assertNotFound();

        // Nothing above the project, so the top level is not a screen it has.
        $this->assertSame(['project', 'worksite'], $this->config()->workStructureLevels());
        $this->getJson('/api/mines')->assertNotFound();
        $this->getJson('/api/lookups/mines')->assertNotFound();

        // But the levels it does use answer normally.
        $this->getJson('/api/projects')->assertOk();
        $this->getJson('/api/worksites')->assertOk();
    }

    public function test_a_five_day_week_changes_the_divisor(): void
    {
        $this->actingAsAdmin();
        $this->applyProfile('construction')->assertOk();
        $this->config()->forget();

        // July 2026: 27 non-Sunday days, 23 weekdays. Every daily rate moves
        // with it, which is why this is the setting and not a label.
        $this->assertSame('mon_fri', $this->config()->workingDayRule());
        $this->assertSame(23, app(WorkingDaysService::class)->derivedForMonth('2026-07'));
    }

    public function test_a_profile_renames_and_relists(): void
    {
        $this->actingAsAdmin();
        $this->applyProfile('construction')->assertOk();

        $terms = $this->getJson('/api/company-settings/terminology')->json('data');
        $this->assertSame('Sites', $terms['nav.worksites']['en']);
        $this->assertSame('Foremen', $terms['nav.masters']['en']);

        // Concrete, not bauxite — with the shipped values retired rather than
        // deleted, because model defaults and the importer still name them.
        $this->assertSame(
            ['concrete', 'asphalt', 'aggregate', 'steel', 'earthworks', 'other'],
            Vocabulary::values('material_type'),
        );
        $this->assertContains('bauxite_ore', Vocabulary::values('material_type', includeInactive: true));
    }

    public function test_labour_services_keeps_the_people_and_drops_the_things(): void
    {
        $this->actingAsAdmin();
        $this->applyProfile('labour_services')->assertOk();
        $this->config()->forget();

        // The service is the hours: nothing is measured at a site, there is no
        // fleet and no border.
        foreach (['production', 'machines', 'customs'] as $absent) {
            $this->assertNotContains($absent, $this->config()->enabledModules(), "{$absent} should be off");
        }

        // What makes this app unlike a generic ERP is exactly what it keeps.
        foreach (['workers', 'attendance', 'salaries', 'housing', 'travel', 'loans'] as $kept) {
            $this->assertContains($kept, $this->config()->enabledModules(), "{$kept} should be on");
        }

        $this->getJson('/api/production')->assertNotFound();
        $this->getJson('/api/houses')->assertOk();
    }

    public function test_applying_a_profile_after_another_leaves_no_trace_of_the_first(): void
    {
        $this->actingAsAdmin();

        $this->applyProfile('labour_services')->assertOk();
        $this->assertSame('Contracts', Terminology::all()['nav.projects']['en'] ?? null);

        $this->applyProfile('construction')->assertOk();

        // Not a merge of two companies' vocabularies: the result is the second
        // profile's wording, and the first profile's words are gone.
        $this->assertArrayNotHasKey('nav.projects', Terminology::all());
        $this->assertSame('Sites', Terminology::all()['nav.worksites']['en']);
    }

    public function test_applying_the_same_profile_twice_changes_nothing(): void
    {
        $this->actingAsAdmin();

        $this->applyProfile('construction')->assertOk();
        $first = [Terminology::all(), Vocabulary::values('material_type'), CompanySettings::current()->only([
            'profile', 'enabled_modules', 'work_structure_levels', 'working_day_rule',
        ])];

        $this->applyProfile('construction')->assertOk();
        $second = [Terminology::all(), Vocabulary::values('material_type'), CompanySettings::current()->only([
            'profile', 'enabled_modules', 'work_structure_levels', 'working_day_rule',
        ])];

        $this->assertEquals($first, $second);
    }

    public function test_an_install_that_is_already_in_use_is_not_reshaped_by_accident(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->count(2)->create();

        // Re-applying rewrites wording and retires list values the existing
        // records hold. Recoverable, but not something anyone means to do by
        // clicking a button on a live system.
        $this->applyProfile('construction')
            ->assertStatus(422)
            ->assertJsonPath('existing_records.workers', 2);

        $this->assertNull($this->config()->profile());

        // Explicitly, it proceeds.
        $this->applyProfile('construction', force: true)->assertOk();
        $this->config()->forget();
        $this->assertSame('construction', $this->config()->profile());
    }

    public function test_a_profile_that_changes_the_week_reprices_the_days_already_entered(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        // Entered on the six-day week the app ships with: 1350 / 27 = 50.00.
        $day = $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated()->json('data.0');

        $this->assertSame(27, $day['working_days_basis']);
        $this->assertEqualsWithDelta(50.0, $day['daily_rate'], 0.01);

        // Construction is a five-day week, which changes what that day is worth.
        // The earned figures are cached on the record, so applying the profile
        // has to carry the change through — exactly as editing the same rule in
        // Settings does. Without this the two ways of making one change
        // disagree, and a month of days stays priced on the old divisor.
        $this->applyProfile('construction', force: true)->assertOk();

        $record = AttendanceRecord::sole();

        $this->assertSame(23, (int) $record->working_days_basis);
        $this->assertEqualsWithDelta(round(1350 / 23, 2), (float) $record->daily_rate, 0.01);
        $this->assertEqualsWithDelta(round(1350 / 23, 2), (float) $record->regular_amount, 0.01);
    }

    public function test_a_profile_that_changes_no_rule_reprices_nothing(): void
    {
        $this->actingAsAdmin();

        $worksite = Worksite::factory()->create();
        $employee = Employee::factory()->create(['base_salary' => 1350]);
        $worksite->employees()->attach($employee->id);

        $this->postJson('/api/attendance', [
            'date' => '2026-07-15',
            'worksite_id' => $worksite->id,
            'records' => [['employee_id' => $employee->id, 'status' => 'present', 'regular_hours' => 8]],
        ])->assertCreated();

        // Mining is the app's own rules restated, so nothing moved and nothing
        // needed rewriting.
        $this->applyProfile('mining', force: true)
            ->assertOk()
            ->assertJsonPath('data.attendance_recomputed', 0);
    }

    public function test_the_setup_prompt_knows_whether_the_install_is_set_up(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/company-settings/profiles')
            ->assertOk()
            ->assertJsonPath('current', null)
            ->assertJsonPath('existing_records', [])
            ->assertJsonPath('data.0.key', 'mining');

        $this->applyProfile('construction')->assertOk();

        $this->getJson('/api/company-settings/profiles')->assertOk()->assertJsonPath('current', 'construction');
    }

    public function test_choosing_a_profile_needs_the_settings_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        // Everyone sees the prompt; only an admin acts on it.
        $this->getJson('/api/company-settings/profiles')->assertOk();
        $this->applyProfile('construction')->assertForbidden();
    }

    public function test_an_unknown_profile_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->applyProfile('shipbuilding')->assertStatus(422)->assertJsonValidationErrors('profile');
    }

    public function test_applying_a_profile_is_audited(): void
    {
        $this->actingAsAdmin();
        $this->applyProfile('construction')->assertOk();

        $entry = ActivityLog::query()->where('description', 'company_profile.applied')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('construction', $entry->properties['profile']);
        $this->assertSame(['project', 'worksite'], $entry->properties['levels']);
    }

    public function test_every_profile_is_internally_consistent(): void
    {
        foreach (app(ProfileRegistry::class)->all() as $key => $profile) {
            foreach ($profile->modules() as $module) {
                $this->assertTrue(Modules::exists($module), "{$key} enables {$module}, which is not a module.");
            }

            // A profile that enables attendance without workers would ship a
            // combination the settings screen itself refuses.
            foreach ($profile->modules() as $module) {
                foreach (Modules::requirements($module) as $required) {
                    $this->assertContains(
                        $required,
                        $profile->modules(),
                        "{$key} enables {$module} without {$required}.",
                    );
                }
            }

            $this->assertContains('worksite', $profile->workStructureLevels(), "{$key} has nowhere to clock in.");

            foreach ($profile->workStructureLevels() as $level) {
                $this->assertContains($level, CompanyConfig::WORK_STRUCTURE_LEVELS, "{$key}: {$level} is not a level.");
            }

            foreach (array_keys($profile->terminology()) as $term) {
                $this->assertTrue(Terminology::isOverridable($term), "{$key} renames {$term}, which is not renameable.");
            }

            foreach (array_keys($profile->vocabularies()) as $vocabulary) {
                $this->assertTrue(Vocabulary::exists($vocabulary), "{$key}: {$vocabulary} is not a vocabulary.");
            }

            foreach ($profile->rules() as $column => $value) {
                $this->assertContains(
                    $column,
                    [...CompanyConfig::payrollRuleColumns(), 'social_assistance_annual'],
                    "{$key} sets {$column}, which is not a rule column.",
                );
            }
        }
    }

    public function test_a_hidden_level_hides_the_screen_but_keeps_the_records(): void
    {
        $this->actingAsAdmin();
        $mine = Mine::factory()->create();

        $this->applyProfile('construction', force: true)->assertOk();
        $this->config()->forget();

        $this->getJson('/api/mines')->assertNotFound();

        // Display depth, not schema depth: the row is untouched and the level
        // comes back whole.
        $this->assertDatabaseHas('rudnici', ['id' => $mine->id]);

        CompanySettings::current()->update(['work_structure_levels' => ['mine', 'project', 'worksite']]);
        $this->config()->forget();

        $this->getJson('/api/mines')->assertOk()->assertJsonPath('data.0.id', $mine->id);
    }

    /*
     * ---------------------------------------------------------------------
     * Whose configuration is it
     *
     * Applying a profile replaces what a profile wrote. It used to replace
     * everything, which meant a company that had spent an afternoon renaming
     * terms lost the lot the next time anybody re-applied — silently, with no
     * undo, on an operation documented as safe to re-run.
     * ---------------------------------------------------------------------
     */

    public function test_applying_a_profile_keeps_terms_the_company_wrote_itself(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.workers' => ['en' => 'Crew']],
        ])->assertOk();

        $this->applyProfile('construction', force: true)->assertOk();

        $this->assertSame(
            'Crew',
            Terminology::all()['nav.workers']['en'] ?? null,
            'A term the company typed was discarded by applying a profile.',
        );
    }

    public function test_applying_a_profile_replaces_the_previous_profiles_words(): void
    {
        $this->actingAsAdmin();

        $this->applyProfile('construction', force: true)->assertOk();
        $fromConstruction = Terminology::all();
        $this->assertNotSame([], $fromConstruction, 'Construction renames nothing — the test proves nothing.');

        $this->applyProfile('labour_services', force: true)->assertOk();

        // The result is the second profile's wording, not a merge of the two.
        foreach (array_keys($fromConstruction) as $key) {
            $stillConstruction = (Terminology::all()[$key] ?? null) === $fromConstruction[$key];

            $this->assertFalse(
                $stillConstruction && ! array_key_exists($key, app(ProfileRegistry::class)->find('labour_services')->terminology()),
                "{$key} kept the previous profile's wording.",
            );
        }
    }

    public function test_correcting_a_profiles_word_makes_it_yours_to_keep(): void
    {
        $this->actingAsAdmin();

        $this->applyProfile('construction', force: true)->assertOk();

        // Whatever construction called it, this company calls it something else.
        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.worksites' => ['en' => 'Yards']],
        ])->assertOk();

        $this->applyProfile('construction', force: true)->assertOk();

        $this->assertSame(
            'Yards',
            Terminology::all()['nav.worksites']['en'] ?? null,
            'Editing a profile-supplied term did not make it the company\'s own.',
        );
    }

    public function test_applying_a_profile_keeps_list_values_the_company_added(): void
    {
        $this->actingAsAdmin();

        $materials = Vocabulary::values('material_type');
        $this->putJson('/api/company-settings/vocabularies/material_type', [
            'values' => [
                ...array_map(
                    fn (string $value, int $index): array => ['value' => $value, 'sort_order' => $index],
                    $materials,
                    array_keys($materials),
                ),
                ['value' => 'crushed_stone', 'sort_order' => count($materials)],
            ],
        ])->assertOk();

        $this->applyProfile('construction', force: true)->assertOk();

        $this->assertContains(
            'crushed_stone',
            Vocabulary::values('material_type'),
            'A list value the company added was retired by applying a profile.',
        );
    }

    public function test_applying_a_profile_still_retires_the_apps_own_values_it_does_not_list(): void
    {
        $this->actingAsAdmin();

        $this->assertContains('bauxite_ore', Vocabulary::values('material_type'));

        $this->applyProfile('construction', force: true)->assertOk();

        // Deactivated, never deleted: rows already holding it still render.
        $this->assertNotContains('bauxite_ore', Vocabulary::values('material_type'));
        $this->assertContains('bauxite_ore', Vocabulary::values('material_type', includeInactive: true));
    }
}
