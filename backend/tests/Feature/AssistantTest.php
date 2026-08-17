<?php

namespace Tests\Feature;

use App\Models\AiSuggestion;
use App\Models\AssistantMessage;
use App\Models\House;
use App\Models\PayableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UtilityBill;
use App\Services\Assistant\AssistantService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class AssistantTest extends TestCase
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

    /** Put a fixed model reply in front of the assistant. */
    private function modelReplies(array $decision): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode($decision)],
                ]],
            ]),
        ]);
    }

    // ---- reading -------------------------------------------------------

    public function test_a_question_is_answered_by_running_a_report(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::factory()->create(['name' => 'North-Ex']);
        $invoice = PayableInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'original_amount' => 750,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
        $invoice->recalculate();

        $this->modelReplies([
            'intent' => 'report',
            'report_key' => 'payables_aging',
            'filters' => [],
            'reply' => 'North-Ex has 750 EUR outstanding.',
        ]);

        $response = $this->postJson('/api/assistant/ask', ['question' => 'What do we owe North-Ex?'])
            ->assertOk()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.intent', 'report');

        $this->assertSame('payables_aging', $response->json('data.data.report.key'));
        $this->assertSame(1, $response->json('data.data.report.row_count'));
        $this->assertSame(750, $response->json('data.data.report.totals.outstanding'));
    }

    public function test_a_question_can_be_answered_from_the_dashboard(): void
    {
        $this->actingAsAdmin();

        $this->modelReplies([
            'intent' => 'dashboard',
            'filters' => ['month' => '2026-07'],
            'reply' => 'Here is July.',
        ]);

        $this->postJson('/api/assistant/ask', ['question' => 'Summarize July income and expenses.'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'dashboard')
            ->assertJsonPath('data.data.dashboard.month', '2026-07-01');
    }

    public function test_the_assistant_can_look_up_who_lives_in_a_house(): void
    {
        $this->actingAsAdmin();
        House::factory()->create(['name' => 'Niksic 1']);

        $this->modelReplies([
            'intent' => 'search',
            'entity' => 'house',
            'query' => 'Niksic',
            'reply' => 'Found one house.',
        ]);

        $this->postJson('/api/assistant/ask', ['question' => 'Who lives in the Niksic house?'])
            ->assertOk()
            ->assertJsonPath('data.data.search.results.0.name', 'Niksic 1');
    }

    public function test_a_report_the_user_may_not_see_is_refused(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['assistant.use', 'payables.view']);
        Sanctum::actingAs($user);

        // The model asks for a housing report this user has no permission for.
        $this->modelReplies([
            'intent' => 'report',
            'report_key' => 'worker_housing_cost_report',
            'filters' => [],
            'reply' => 'Here is the housing cost.',
        ]);

        $this->postJson('/api/assistant/ask', ['question' => 'What is housing costing us?'])
            ->assertOk()
            ->assertJsonPath('data.data.error', 'forbidden');
    }

    public function test_an_unknown_report_key_does_not_break_the_answer(): void
    {
        $this->actingAsAdmin();

        $this->modelReplies([
            'intent' => 'report',
            'report_key' => 'something_invented',
            'filters' => [],
            'reply' => 'Here you go.',
        ]);

        $this->postJson('/api/assistant/ask', ['question' => 'anything'])
            ->assertOk()
            ->assertJsonPath('data.data.error', 'unknown_report');
    }

    public function test_a_reply_the_app_does_not_understand_falls_back_to_plain_text(): void
    {
        $this->actingAsAdmin();

        $this->modelReplies(['intent' => 'delete_everything', 'reply' => 'Sure!']);

        $this->postJson('/api/assistant/ask', ['question' => 'delete all invoices'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'answer');

        // Nothing was touched.
        $this->assertSame(0, PayableInvoice::query()->count());
    }

    // ---- the safety flow -----------------------------------------------

    public function test_a_proposed_record_is_not_saved_until_the_user_confirms(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create(['name' => 'Podgorica']);

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            'fields' => [
                'house_id' => $house->id,
                'bill_type' => 'electricity',
                'billing_period' => '2026-07-01',
                'amount' => 84.5,
            ],
            'reply' => 'I can add a 84.50 EUR electricity bill for Podgorica.',
        ]);

        $response = $this->postJson('/api/assistant/ask', [
            'question' => 'Add electricity bill for Podgorica house, 84.50 euro, July.',
        ])->assertOk();

        $suggestionId = $response->json('data.suggestion.id');
        $this->assertNotNull($suggestionId);
        $response->assertJsonPath('data.suggestion.status', 'pending');

        // The heart of rule 2: proposing writes nothing.
        $this->assertSame(0, UtilityBill::query()->count());

        $this->postJson("/api/assistant/suggestions/{$suggestionId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.suggestion.status', 'confirmed')
            ->assertJsonPath('data.record.type', 'UtilityBill');

        $bill = UtilityBill::query()->first();
        $this->assertSame('84.50', $bill->amount);
        $this->assertSame('assistant', $bill->source);
        // The balance is still derived, not taken from the model.
        $this->assertSame('84.50', $bill->remaining_amount);
    }

    public function test_confirming_is_recorded_in_the_audit_log(): void
    {
        $user = $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            'fields' => [
                'house_id' => $house->id,
                'bill_type' => 'water',
                'billing_period' => '2026-07-01',
                'amount' => 30,
            ],
            'reply' => 'Proposed.',
        ]);

        $id = $this->postJson('/api/assistant/ask', ['question' => 'add water bill'])->json('data.suggestion.id');
        $this->postJson("/api/assistant/suggestions/{$id}/confirm")->assertOk();

        $this->assertDatabaseHas('dnevnik_aktivnosti', [
            'description' => 'assistant.suggestion_confirmed',
            'causer_id' => $user->id,
        ]);
    }

    public function test_a_rejected_suggestion_never_becomes_a_record(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            'fields' => [
                'house_id' => $house->id,
                'bill_type' => 'internet',
                'billing_period' => '2026-07-01',
                'amount' => 25,
            ],
            'reply' => 'Proposed.',
        ]);

        $id = $this->postJson('/api/assistant/ask', ['question' => 'add internet bill'])->json('data.suggestion.id');

        $this->postJson("/api/assistant/suggestions/{$id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, UtilityBill::query()->count());

        // And a rejected one cannot then be applied.
        $this->postJson("/api/assistant/suggestions/{$id}/confirm")->assertStatus(422);
        $this->assertSame(0, UtilityBill::query()->count());
    }

    public function test_a_suggestion_cannot_be_confirmed_twice(): void
    {
        $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            'fields' => [
                'house_id' => $house->id,
                'bill_type' => 'heating',
                'billing_period' => '2026-07-01',
                'amount' => 60,
            ],
            'reply' => 'Proposed.',
        ]);

        $id = $this->postJson('/api/assistant/ask', ['question' => 'add heating bill'])->json('data.suggestion.id');

        $this->postJson("/api/assistant/suggestions/{$id}/confirm")->assertOk();
        $this->postJson("/api/assistant/suggestions/{$id}/confirm")->assertStatus(422);

        $this->assertSame(1, UtilityBill::query()->count());
    }

    public function test_an_invalid_proposal_is_shown_with_its_errors_and_cannot_be_applied(): void
    {
        $this->actingAsAdmin();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            // A house that does not exist and a missing amount.
            'fields' => ['house_id' => 9999, 'bill_type' => 'electricity', 'billing_period' => '2026-07-01'],
            'reply' => 'Proposed.',
        ]);

        $response = $this->postJson('/api/assistant/ask', ['question' => 'add a bill'])->assertOk();

        $response->assertJsonPath('data.suggestion.status', 'invalid');
        $this->assertNotNull($response->json('data.suggestion.errors.house_id'));
        $this->assertNotNull($response->json('data.suggestion.errors.amount'));

        $id = $response->json('data.suggestion.id');
        $this->postJson("/api/assistant/suggestions/{$id}/confirm")->assertStatus(422);
        $this->assertSame(0, UtilityBill::query()->count());
    }

    public function test_the_assistant_cannot_propose_a_target_it_has_no_permission_for(): void
    {
        $user = User::factory()->create();
        // Can use the assistant, but has no housing permission.
        $user->givePermissionTo(['assistant.use']);
        Sanctum::actingAs($user);

        $house = House::factory()->create();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            'fields' => [
                'house_id' => $house->id,
                'bill_type' => 'electricity',
                'billing_period' => '2026-07-01',
                'amount' => 50,
            ],
            'reply' => 'Proposed.',
        ]);

        $this->postJson('/api/assistant/ask', ['question' => 'add a bill'])
            ->assertOk()
            ->assertJsonPath('data.suggestion', null);

        $this->assertSame(0, AiSuggestion::query()->count());
        $this->assertSame(0, UtilityBill::query()->count());
    }

    public function test_the_assistant_refuses_to_create_a_kind_of_record_outside_its_list(): void
    {
        $this->actingAsAdmin();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'employee',
            'fields' => ['first_name' => 'Ghost'],
            'reply' => 'Adding a worker.',
        ]);

        $this->postJson('/api/assistant/ask', ['question' => 'add a worker called Ghost'])
            ->assertOk()
            ->assertJsonPath('data.suggestion', null);

        $this->assertSame(0, AiSuggestion::query()->count());
    }

    public function test_one_user_cannot_confirm_another_users_suggestion(): void
    {
        $owner = $this->actingAsAdmin();
        $house = House::factory()->create();

        $this->modelReplies([
            'intent' => 'create_record',
            'target' => 'utility_bill',
            'fields' => [
                'house_id' => $house->id,
                'bill_type' => 'garbage',
                'billing_period' => '2026-07-01',
                'amount' => 15,
            ],
            'reply' => 'Proposed.',
        ]);

        $id = $this->postJson('/api/assistant/ask', ['question' => 'add garbage bill'])->json('data.suggestion.id');

        $intruder = User::factory()->create();
        $intruder->assignRole('Admin');
        Sanctum::actingAs($intruder);

        $this->postJson("/api/assistant/suggestions/{$id}/confirm")->assertNotFound();
        $this->assertSame(0, UtilityBill::query()->count());
        $this->assertSame($owner->id, AiSuggestion::query()->first()->user_id);
    }

    // ---- conversation & access ------------------------------------------

    public function test_the_conversation_is_kept_and_is_private_to_its_user(): void
    {
        $user = $this->actingAsAdmin();
        $this->modelReplies(['intent' => 'answer', 'reply' => 'Hello.']);

        $this->postJson('/api/assistant/ask', ['question' => 'hi'])->assertOk();

        $this->getJson('/api/assistant/messages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role', 'user')
            ->assertJsonPath('data.1.role', 'assistant');

        $other = User::factory()->create();
        $other->assignRole('Admin');
        Sanctum::actingAs($other);

        $this->getJson('/api/assistant/messages')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($user);
        $this->deleteJson('/api/assistant/messages')->assertOk();
        $this->assertSame(0, AssistantMessage::query()->forUser($user->id)->count());
    }

    public function test_a_model_failure_is_reported_rather_than_crashing(): void
    {
        $this->actingAsAdmin();

        // No fake registered and no API key: the call throws.
        config(['openai.api_key' => null]);

        $this->postJson('/api/assistant/ask', ['question' => 'hello'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'error');
    }

    public function test_the_assistant_requires_its_own_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        Sanctum::actingAs($user);

        $this->postJson('/api/assistant/ask', ['question' => 'hi'])->assertForbidden();
        $this->getJson('/api/assistant/messages')->assertForbidden();
    }

    public function test_the_context_given_to_the_model_respects_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['assistant.use', 'payables.view']);

        Supplier::factory()->create(['name' => 'Visible DOO']);
        House::factory()->create(['name' => 'Hidden house']);

        $context = app(AssistantService::class)->context($user);

        $this->assertArrayHasKey('suppliers', $context);
        // No housing permission, so houses are never put in front of the model.
        $this->assertArrayNotHasKey('houses', $context);
        $this->assertArrayNotHasKey('clients', $context);
    }
}
