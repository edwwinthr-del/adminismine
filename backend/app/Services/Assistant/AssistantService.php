<?php

namespace App\Services\Assistant;

use App\Models\AiSuggestion;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Employee;
use App\Models\House;
use App\Models\Supplier;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Validator;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * The spec's safety flow, in order:
 *
 *   select safe context → ask OpenAI → validate the reply → preview → confirm → save → audit
 *
 * Two things make it safe rather than merely careful. The model never receives a
 * database handle: it picks an intent from a fixed list and Laravel runs it,
 * permission-checked, against the same services the UI uses. And nothing the
 * model proposes is written — `create_record` produces a suggestion that only a
 * human confirmation turns into a record.
 */
class AssistantService
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly DashboardService $dashboard,
        private readonly SuggestionValidator $validator,
    ) {}

    /**
     * @return array{message: AssistantMessage, suggestion: AiSuggestion|null}
     */
    public function ask(User $user, string $question): array
    {
        AssistantMessage::create(['user_id' => $user->id, 'role' => 'user', 'content' => $question]);

        try {
            $decision = $this->decide($user, $question);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'message' => $this->reply($user, __('The assistant is unavailable right now.'), 'error', [
                    'error' => $exception->getMessage(),
                ]),
                'suggestion' => null,
            ];
        }

        $intent = $decision['intent'] ?? 'answer';
        $reply = trim((string) ($decision['reply'] ?? ''));

        if (! in_array($intent, AssistantIntent::all(), true)) {
            return [
                'message' => $this->reply($user, $reply ?: __('I could not work out what to look up.'), 'answer'),
                'suggestion' => null,
            ];
        }

        if (AssistantIntent::isWrite($intent)) {
            return $this->proposeRecord($user, $question, $decision, $reply);
        }

        return [
            'message' => $this->reply($user, $reply, $intent, $this->runRead($user, $intent, $decision)),
            'suggestion' => null,
        ];
    }

    /**
     * Ask the model what to do. It gets the question plus a small, permission-
     * scoped summary — never table dumps.
     *
     * @return array<string, mixed>
     */
    private function decide(User $user, string $question): array
    {
        $available = array_keys($this->reports->availableTo($user));

        $response = OpenAI::chat()->create([
            'model' => config('services.openai.model', 'gpt-4o-mini'),
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => AssistantIntent::systemPrompt($this->reports, $available, now()->toDateString()),
                ],
                ['role' => 'system', 'content' => 'Context: '.json_encode($this->context($user))],
                ['role' => 'user', 'content' => $question],
            ],
        ]);

        $content = $response->choices[0]->message->content ?? '{}';

        return json_decode($content, true) ?: [];
    }

    /**
     * The "safe context" step: names and counts the user may already see, so the
     * model can resolve "the Niksic house" or "North-Ex" without being handed
     * the books.
     *
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $context = ['today' => now()->toDateString(), 'currency' => 'EUR'];

        if ($user->can('payables.view')) {
            $context['suppliers'] = Supplier::query()->orderBy('name')->limit(60)->pluck('name', 'id');
        }
        if ($user->can('receivables.manage')) {
            $context['clients'] = Client::query()->orderBy('name')->limit(60)->pluck('name', 'id');
        }
        if ($user->can('housing.manage')) {
            $context['houses'] = House::query()->orderBy('name')->limit(60)->pluck('name', 'id');
        }
        if ($user->can('employees.manage')) {
            $context['workers'] = Employee::query()->active()->limit(120)->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->full_name]);
        }

        return $context;
    }

    /**
     * Run a read intent through the same permission-checked services the UI
     * uses, so the assistant can never see more than the user can.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function runRead(User $user, string $intent, array $decision): array
    {
        return match ($intent) {
            'report' => $this->runReport($user, $decision),
            'dashboard' => ['dashboard' => $this->dashboard->forUser($user, $decision['filters']['month'] ?? null)],
            'search' => $this->runSearch($user, $decision),
            default => [],
        };
    }

    /** @param array<string, mixed> $decision */
    private function runReport(User $user, array $decision): array
    {
        $key = (string) ($decision['report_key'] ?? '');
        $report = $this->reports->find($key);

        if ($report === null) {
            return ['error' => 'unknown_report'];
        }

        if (! $user->can($report->permission())) {
            return ['error' => 'forbidden'];
        }

        $filters = array_intersect_key(
            (array) ($decision['filters'] ?? []),
            array_flip($report->filters()),
        );
        $rows = $report->rows($filters);

        return [
            'report' => [
                'key' => $report->key(),
                'columns' => $report->columns(),
                // Capped: the reply is a summary, and the full table lives on
                // the reports page.
                'rows' => array_slice($rows, 0, 25),
                'row_count' => count($rows),
                'totals' => $report->totals($rows),
                'filters' => $filters,
            ],
        ];
    }

    /** @param array<string, mixed> $decision */
    private function runSearch(User $user, array $decision): array
    {
        $entity = (string) ($decision['entity'] ?? '');
        $query = trim((string) ($decision['query'] ?? ''));

        if ($query === '' || ! in_array($entity, AssistantIntent::SEARCH_ENTITIES, true)) {
            return ['error' => 'bad_search'];
        }

        [$model, $permission] = match ($entity) {
            'supplier' => [Supplier::class, 'payables.view'],
            'client' => [Client::class, 'receivables.manage'],
            'house' => [House::class, 'housing.manage'],
            'employee' => [Employee::class, 'employees.manage'],
        };

        if (! $user->can($permission)) {
            return ['error' => 'forbidden'];
        }

        if ($entity === 'employee') {
            $results = Employee::query()->search($query)
                ->limit(20)->get(['id', 'first_name', 'last_name'])
                ->map(fn (Employee $e): array => ['id' => $e->id, 'name' => $e->full_name]);
        } else {
            $results = $model::query()->search($query)
                ->limit(20)->get(['id', 'name'])
                ->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name]);
        }

        return ['search' => ['entity' => $entity, 'query' => $query, 'results' => $results]];
    }

    /**
     * A proposed record. It is validated here — so the preview shows real
     * validation errors rather than the model's optimism — and stored as a
     * suggestion. Nothing is written yet.
     *
     * @param  array<string, mixed>  $decision
     * @return array{message: AssistantMessage, suggestion: AiSuggestion|null}
     */
    private function proposeRecord(User $user, string $question, array $decision, string $reply): array
    {
        $target = (string) ($decision['target'] ?? '');
        $fields = (array) ($decision['fields'] ?? []);

        if (! in_array($target, AssistantIntent::CREATE_TARGETS, true)) {
            return [
                'message' => $this->reply($user, __('I cannot create that kind of record.'), 'answer'),
                'suggestion' => null,
            ];
        }

        if (! $user->can($this->validator->permissionFor($target))) {
            return [
                'message' => $this->reply($user, __('You do not have permission to create that.'), 'answer'),
                'suggestion' => null,
            ];
        }

        $validation = Validator::make($fields, $this->validator->rulesFor($target));
        $valid = ! $validation->fails();

        $suggestion = AiSuggestion::create([
            'user_id' => $user->id,
            'kind' => 'create_record',
            'target' => $target,
            'proposed' => $fields,
            'validated' => $valid ? $validation->validated() : null,
            'errors' => $valid ? null : $validation->errors()->toArray(),
            'prompt' => $question,
            'source' => 'assistant',
        ]);

        if (! $valid) {
            $suggestion->forceFill(['status' => 'invalid'])->save();
        }

        activity()->performedOn($suggestion)->causedBy($user)
            ->withProperties(['target' => $target, 'valid' => $valid])
            ->log('assistant.suggestion_created');

        $message = $this->reply(
            $user,
            $reply ?: __('Here is what I would create. Nothing is saved until you confirm.'),
            'create_record',
            ['suggestion_id' => $suggestion->id],
        );
        $message->forceFill(['ai_suggestion_id' => $suggestion->id])->save();

        return ['message' => $message->fresh(), 'suggestion' => $suggestion];
    }

    /** @param array<string, mixed> $data */
    private function reply(User $user, string $content, string $intent, array $data = []): AssistantMessage
    {
        return AssistantMessage::create([
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $content,
            'intent' => $intent,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
