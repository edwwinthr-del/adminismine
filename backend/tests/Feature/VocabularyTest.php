<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\Machine;
use App\Models\ProductionRecord;
use App\Models\User;
use App\Models\VocabularyValue;
use App\Models\Worksite;
use App\Support\Vocabulary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The open-ended lists a company fills in for itself.
 *
 * The line these tests exist to hold: **a value the code branches on is an enum
 * and stays in PHP; a value only humans read is a vocabulary.** `bauxite_ore`
 * decides nothing, so it can be replaced. `present` decides whether a day earns
 * and `maintenance` decides whether a machine is in service — those are the
 * program, and there is no editor over them.
 */
class VocabularyTest extends TestCase
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

    /** The whole list as the API wants it back. */
    private function listOf(string ...$values): array
    {
        return ['values' => array_map(
            fn (string $value, int $index): array => ['value' => $value, 'sort_order' => $index],
            $values,
            array_keys($values),
        )];
    }

    public function test_the_shipped_values_are_what_the_app_always_offered(): void
    {
        $this->assertSame(
            ['bauxite_ore', 'overburden', 'limestone', 'other'],
            Vocabulary::values('material_type'),
        );
        $this->assertSame(['tons', 'm3', 'kg'], Vocabulary::values('production_unit'));
    }

    public function test_a_company_can_replace_the_material_list_outright(): void
    {
        $this->actingAsAdmin();
        $worksite = Worksite::factory()->create();

        // A construction firm's list, with the shipped values switched off
        // rather than removed.
        $this->putJson('/api/company-settings/vocabularies/material_type', [
            'values' => [
                ['value' => 'concrete', 'sort_order' => 0],
                ['value' => 'asphalt', 'sort_order' => 1],
                ['value' => 'bauxite_ore', 'sort_order' => 2, 'is_active' => false],
                ['value' => 'overburden', 'sort_order' => 3, 'is_active' => false],
                ['value' => 'limestone', 'sort_order' => 4, 'is_active' => false],
                ['value' => 'other', 'sort_order' => 5],
            ],
        ])->assertOk();

        $this->assertSame(['concrete', 'asphalt', 'other'], Vocabulary::values('material_type'));

        // No migration, no code change: the form accepts the new value.
        $this->postJson('/api/production', [
            'worksite_id' => $worksite->id,
            'date' => '2026-07-15',
            'period_type' => 'daily',
            'material_type' => 'concrete',
            'quantity' => 40,
            'unit' => 'm3',
        ])->assertCreated()->assertJsonPath('data.material_type', 'concrete');

        // And refuses one that is switched off.
        $this->postJson('/api/production', [
            'worksite_id' => $worksite->id,
            'date' => '2026-07-16',
            'period_type' => 'daily',
            'material_type' => 'bauxite_ore',
            'quantity' => 40,
            'unit' => 'm3',
        ])->assertStatus(422)->assertJsonValidationErrors('material_type');
    }

    public function test_records_holding_a_retired_value_are_untouched(): void
    {
        $this->actingAsAdmin();
        $record = ProductionRecord::factory()->create(['material_type' => 'bauxite_ore']);

        $this->putJson('/api/company-settings/vocabularies/material_type', [
            'values' => [
                ['value' => 'bauxite_ore', 'sort_order' => 0, 'is_active' => false],
                ['value' => 'overburden', 'sort_order' => 1, 'is_active' => false],
                ['value' => 'limestone', 'sort_order' => 2, 'is_active' => false],
                ['value' => 'other', 'sort_order' => 3],
                ['value' => 'concrete', 'sort_order' => 4],
            ],
        ])->assertOk();

        // Deactivating is not deleting: the row still says what it always said,
        // and still resolves a label.
        $this->assertSame('bauxite_ore', $record->fresh()->material_type);
        $this->assertContains('bauxite_ore', Vocabulary::values('material_type', includeInactive: true));
        $this->getJson('/api/production')->assertOk()->assertJsonPath('data.0.material_type', 'bauxite_ore');
    }

    public function test_a_shipped_value_can_be_switched_off_but_not_removed(): void
    {
        $this->actingAsAdmin();

        // A model default names some of these and the workbook importer's label
        // normaliser maps onto others; removing the row would leave code
        // pointing at something that is not there.
        $this->putJson('/api/company-settings/vocabularies/production_unit', $this->listOf('m3', 'kg'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('values');

        $this->putJson('/api/company-settings/vocabularies/production_unit', [
            'values' => [
                ['value' => 'tons', 'sort_order' => 0, 'is_active' => false],
                ['value' => 'm3', 'sort_order' => 1],
                ['value' => 'kg', 'sort_order' => 2],
            ],
        ])->assertOk();

        $this->assertSame(['m3', 'kg'], Vocabulary::values('production_unit'));
    }

    public function test_a_value_records_still_hold_cannot_be_removed(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/vocabularies/material_type', [
            'values' => [
                ['value' => 'bauxite_ore', 'sort_order' => 0],
                ['value' => 'overburden', 'sort_order' => 1],
                ['value' => 'limestone', 'sort_order' => 2],
                ['value' => 'other', 'sort_order' => 3],
                ['value' => 'concrete', 'sort_order' => 4],
            ],
        ])->assertOk();

        ProductionRecord::factory()->create(['material_type' => 'concrete']);

        // Removing it would leave those rows naming something nothing can label.
        $this->putJson('/api/company-settings/vocabularies/material_type', [
            'values' => [
                ['value' => 'bauxite_ore', 'sort_order' => 0],
                ['value' => 'overburden', 'sort_order' => 1],
                ['value' => 'limestone', 'sort_order' => 2],
                ['value' => 'other', 'sort_order' => 3],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('values');
    }

    public function test_an_unused_custom_value_can_be_removed(): void
    {
        $this->actingAsAdmin();

        $withExtra = ['bauxite_ore', 'overburden', 'limestone', 'other', 'typo_value'];
        $this->putJson('/api/company-settings/vocabularies/material_type', $this->listOf(...$withExtra))->assertOk();
        $this->assertContains('typo_value', Vocabulary::values('material_type'));

        $this->putJson(
            '/api/company-settings/vocabularies/material_type',
            $this->listOf('bauxite_ore', 'overburden', 'limestone', 'other'),
        )->assertOk();

        $this->assertNotContains('typo_value', Vocabulary::values('material_type', includeInactive: true));
    }

    public function test_a_stored_value_stays_canonical_whatever_it_is_called(): void
    {
        $this->actingAsAdmin();

        // Rule 4: what is stored is a language-neutral key. The wording belongs
        // to the terminology layer, where it can differ per language.
        foreach (['Crushed Stone', 'crushed stone', 'kamen-drobljeni', '3rd_grade'] as $bad) {
            $this->putJson('/api/company-settings/vocabularies/material_type', [
                'values' => [['value' => $bad, 'sort_order' => 0]],
            ])->assertStatus(422)->assertJsonValidationErrors('values.0.value');
        }
    }

    public function test_a_custom_value_can_be_named_in_every_language(): void
    {
        $this->actingAsAdmin();

        $this->putJson(
            '/api/company-settings/vocabularies/material_type',
            $this->listOf('bauxite_ore', 'overburden', 'limestone', 'other', 'crushed_stone'),
        )->assertOk();

        // A value the company invented has no built-in wording, so the
        // terminology layer accepts a key for it the moment it exists.
        $this->putJson('/api/company-settings/terminology', [
            'terms' => [
                'vocab.material_type.crushed_stone' => ['en' => 'Crushed stone', 'sr' => 'Drobljeni kamen'],
            ],
        ])->assertOk();

        $data = $this->getJson('/api/company-settings/terminology')->json('data');
        $this->assertSame('Crushed stone', $data['vocab.material_type.crushed_stone']['en']);
    }

    public function test_a_label_for_a_value_that_does_not_exist_is_refused(): void
    {
        $this->actingAsAdmin();

        // Still a whitelist: inventing a label key is not a way around it.
        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['vocab.material_type.never_added' => ['en' => 'Nope']],
        ])->assertStatus(422)->assertJsonValidationErrors('terms');

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['vocab.not_a_vocabulary.x' => ['en' => 'Nope']],
        ])->assertStatus(422);
    }

    public function test_the_import_template_offers_the_companys_own_values(): void
    {
        $this->actingAsAdmin();

        $this->putJson(
            '/api/company-settings/vocabularies/travel_expense_type',
            $this->listOf('car', 'flight', 'bus', 'taxi', 'fuel', 'accommodation', 'meal', 'other', 'ferry'),
        )->assertOk();

        $entity = collect($this->getJson('/api/imports/entities')->json('data'))
            ->firstWhere('key', 'travel_expense');

        $column = collect($entity['columns'])->firstWhere('key', 'expense_type');

        // A downloaded template is by construction the format the importer
        // accepts, so it has to offer what this company actually uses.
        $this->assertContains('ferry', $column['values']);
    }

    public function test_what_the_code_branches_on_is_not_a_vocabulary(): void
    {
        // The line, asserted rather than described. Attendance statuses decide
        // whether a day earns; machine statuses decide whether a machine counts
        // as in service (Machine::scopeActive). Neither is editable.
        $this->assertFalse(Vocabulary::exists('attendance_status'));
        $this->assertFalse(Vocabulary::exists('approval_status'));
        $this->assertFalse(Vocabulary::exists('machine_status'));
        $this->assertFalse(Vocabulary::exists('movement_category'));

        $this->assertSame(
            ['present', 'absent', 'holiday', 'sick_leave', 'unpaid_leave', 'other'],
            AttendanceRecord::STATUSES,
        );
        $this->assertSame(['active', 'maintenance', 'inactive', 'sold'], Machine::STATUSES);
    }

    public function test_every_vocabulary_in_the_catalogue_is_seeded(): void
    {
        foreach (Vocabulary::keys() as $vocabulary) {
            $this->assertSame(
                Vocabulary::defaults($vocabulary),
                Vocabulary::values($vocabulary),
                "{$vocabulary} does not offer what it ships with.",
            );

            $this->assertTrue(
                VocabularyValue::query()->where('vocabulary', $vocabulary)->where('is_system', true)->exists(),
                "{$vocabulary} has no seeded values.",
            );
        }
    }

    public function test_an_unknown_vocabulary_is_not_editable(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/vocabularies/attendance_status', $this->listOf('present'))
            ->assertNotFound();
    }

    public function test_editing_a_vocabulary_needs_the_settings_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        // Reading is open: every form that offers a list has to know what is in it.
        $this->getJson('/api/company-settings/vocabularies')->assertOk();

        $this->putJson('/api/company-settings/vocabularies/material_type', $this->listOf('concrete'))
            ->assertForbidden();
    }

    public function test_the_change_is_audited(): void
    {
        $this->actingAsAdmin();

        $this->putJson(
            '/api/company-settings/vocabularies/material_type',
            $this->listOf('bauxite_ore', 'overburden', 'limestone', 'other', 'concrete'),
        )->assertOk();

        $entry = ActivityLog::query()
            ->where('description', 'vocabulary.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('material_type', $entry->properties['vocabulary']);
        $this->assertContains('concrete', $entry->properties['values']);
    }
}
