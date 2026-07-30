<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\AssistantMessage;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\SuggestionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The assistant's API. Asking a question never changes anything; the only route
 * that writes is `confirm`, and it only ever applies a suggestion the user is
 * looking at.
 */
class AssistantController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        $messages = AssistantMessage::query()
            ->forUser($request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        $suggestions = AiSuggestion::query()
            ->whereIn('id', $messages->pluck('ai_suggestion_id')->filter())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $messages->map(fn (AssistantMessage $message): array => $this->present($message, $suggestions)),
        ]);
    }

    public function ask(Request $request, AssistantService $assistant): JsonResponse
    {
        $request->validate(['question' => ['required', 'string', 'max:2000']]);

        $result = $assistant->ask($request->user(), $request->string('question')->toString());

        activity()->causedBy($request->user())
            ->withProperties(['intent' => $result['message']->intent])
            ->log('assistant.asked');

        return response()->json([
            'data' => $this->present(
                $result['message'],
                collect($result['suggestion'] ? [$result['suggestion']->id => $result['suggestion']] : []),
            ),
        ]);
    }

    /** The confirmation step. This is the only place an AI proposal becomes a record. */
    public function confirm(Request $request, AiSuggestion $suggestion, SuggestionValidator $validator): JsonResponse
    {
        abort_unless($suggestion->user_id === $request->user()->id, 404);

        try {
            $record = $validator->apply($suggestion, $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'suggestion' => $this->suggestion($suggestion->fresh()),
                'record' => ['type' => class_basename($record), 'id' => $record->getKey()],
            ],
        ]);
    }

    public function reject(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        abort_unless($suggestion->user_id === $request->user()->id, 404);
        abort_if($suggestion->status === 'confirmed', 422, 'This suggestion has already been applied.');

        $suggestion->forceFill(['status' => 'rejected'])->save();

        activity()->performedOn($suggestion)->causedBy($request->user())
            ->log('assistant.suggestion_rejected');

        return response()->json(['data' => $this->suggestion($suggestion->fresh())]);
    }

    public function clear(Request $request): JsonResponse
    {
        AssistantMessage::query()->forUser($request->user()->id)->delete();

        return response()->json(['message' => 'Conversation cleared.']);
    }

    /** @param Collection<int, AiSuggestion> $suggestions */
    private function present(AssistantMessage $message, $suggestions): array
    {
        $suggestion = $message->ai_suggestion_id === null
            ? null
            : $suggestions->get($message->ai_suggestion_id);

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'intent' => $message->intent,
            'data' => $message->data,
            'suggestion' => $suggestion === null ? null : $this->suggestion($suggestion),
            'created_at' => $message->created_at,
        ];
    }

    private function suggestion(AiSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'kind' => $suggestion->kind,
            'target' => $suggestion->target,
            'proposed' => $suggestion->proposed,
            'validated' => $suggestion->validated,
            'errors' => $suggestion->errors,
            'status' => $suggestion->status,
            'record_id' => $suggestion->record_id,
            'confirmed_at' => $suggestion->confirmed_at,
        ];
    }
}
