<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Mine;
use App\Models\TerminologyOverride;
use App\Models\User;
use App\Support\Terminology;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What this company calls the things the app models.
 *
 * The point of these tests is the line the feature must not cross: a renamed
 * screen is a *screen*. The route, the table, the permission and every stored
 * value stay exactly what they were (rule 4), and only the whitelisted domain
 * nouns can be renamed at all.
 */
class TerminologyTest extends TestCase
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

    public function test_a_fresh_install_has_renamed_nothing(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/company-settings/terminology')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('locales', ['en', 'sr', 'tr'])
            ->assertJsonPath('groups.Work structure.mines.0', 'nav.mines');
    }

    public function test_a_term_can_be_renamed_in_every_language(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => [
                'nav.mines' => ['en' => 'Sites', 'sr' => 'Lokacije', 'tr' => 'Sahalar'],
            ],
        ])->assertOk()->assertJsonPath('changed', 3);

        // Read out of the decoded body rather than by dot path: the keys are
        // themselves dotted, which is exactly what dot notation cannot express.
        $data = $this->getJson('/api/company-settings/terminology')->assertOk()->json('data');

        $this->assertSame(
            ['en' => 'Sites', 'sr' => 'Lokacije', 'tr' => 'Sahalar'],
            $data['nav.mines'],
        );
    }

    public function test_renaming_a_screen_changes_nothing_underneath_it(): void
    {
        $this->actingAsAdmin();
        $mine = Mine::factory()->create();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.mines' => ['en' => 'Sites']],
        ])->assertOk();

        // The whole discipline of this feature, in one test: the word moved and
        // nothing else did.
        $this->getJson('/api/mines')->assertOk()->assertJsonPath('data.0.id', $mine->id);
        $this->assertDatabaseHas('rudnici', ['id' => $mine->id]);
        $this->assertTrue($this->actingAsAdmin()->can('worksites.manage'));
    }

    public function test_one_language_can_be_renamed_without_the_others(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.worksites' => ['sr' => 'Lokacije']],
        ])->assertOk();

        $data = $this->getJson('/api/company-settings/terminology')->json('data');

        // English and Turkish keep the built-in wording rather than falling back
        // to somebody else's language.
        $this->assertSame(['sr' => 'Lokacije'], $data['nav.worksites']);
    }

    public function test_clearing_a_term_restores_the_built_in_wording(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.mines' => ['en' => 'Sites']],
        ])->assertOk();

        $this->assertDatabaseHas('prevodi_pojmova', ['key' => 'nav.mines', 'locale' => 'en']);

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.mines' => ['en' => '']],
        ])->assertOk();

        // The absence of a row is what "not renamed" means — an empty string
        // would be a second way to say the same thing.
        $this->assertDatabaseMissing('prevodi_pojmova', ['key' => 'nav.mines', 'locale' => 'en']);
    }

    public function test_only_the_domain_nouns_can_be_renamed(): void
    {
        $this->actingAsAdmin();

        // Renaming "Save" is not terminology; it is a way to make the app
        // unusable, and an editor over 1,400 keys would eventually be used so.
        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['common.save' => ['en' => 'Yeet']],
        ])->assertStatus(422)->assertJsonValidationErrors('terms');

        $this->assertDatabaseCount('prevodi_pojmova', 0);
    }

    public function test_a_term_that_is_not_a_term_at_all_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.doesNotExist' => ['en' => 'Something']],
        ])->assertStatus(422)->assertJsonValidationErrors('terms');
    }

    public function test_a_term_is_a_term_not_a_paragraph(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.mines' => ['en' => str_repeat('a', 121)]],
        ])->assertStatus(422);
    }

    public function test_everyone_reads_the_terms_but_only_an_admin_sets_them(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Worker');
        Sanctum::actingAs($user);

        // The whole app renders through these: gating the read would leave
        // everyone but an admin looking at different words.
        $this->getJson('/api/company-settings/terminology')->assertOk();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.mines' => ['en' => 'Sites']],
        ])->assertForbidden();
    }

    public function test_the_change_is_audited(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company-settings/terminology', [
            'terms' => ['nav.mines' => ['en' => 'Sites', 'sr' => 'Lokacije']],
        ])->assertOk();

        $entry = ActivityLog::query()
            ->where('description', 'company_terminology.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertContains('nav.mines.en', $entry->properties['changed']);
    }

    public function test_a_key_dropped_from_the_whitelist_stops_taking_effect(): void
    {
        $this->actingAsAdmin();

        // Written straight to the table, as a row left behind by an older
        // release would be.
        TerminologyOverride::create(['key' => 'common.save', 'locale' => 'en', 'value' => 'Yeet']);

        $this->assertArrayNotHasKey('common.save', Terminology::all());
    }

    public function test_a_rename_covers_every_label_that_names_the_thing(): void
    {
        // The first cut of this whitelist held only the menu entry and the
        // heading, so renaming Mines to Sites left the page saying "New mine"
        // under a heading that said "Sites". A term is the noun *and* the
        // labels that name it.
        $keys = Terminology::GROUPS['Work structure']['mines'];

        foreach (['nav.mines', 'mines.title', 'mines.subtitle', 'mines.new', 'mines.search', 'mines.none'] as $key) {
            $this->assertContains($key, $keys, "{$key} is part of renaming a mine and must be overridable.");
        }
    }

    public function test_every_whitelisted_key_is_a_real_dictionary_key(): void
    {
        // The editor shows the built-in wording as the placeholder, so a key
        // that does not exist in the frontend dictionary would render an empty
        // row nobody could interpret.
        $dictionary = file_get_contents(base_path('../frontend/src/lib/i18n/dictionaries.ts'));

        foreach (Terminology::overridable() as $key) {
            $this->assertStringContainsString(
                '"'.$key.'":',
                $dictionary,
                "{$key} is overridable but is not a key in the frontend dictionary.",
            );
        }
    }
}
