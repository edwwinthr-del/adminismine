<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Models\UserNotificationPreference;
use App\Support\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The signed-in user's own opt-outs — never anybody else's. */
class NotificationPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $saved = UserNotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->keyBy('type');

        // Every type is returned, so the UI never has to guess what exists;
        // `is_enabled: null` means the user has not overridden the rule.
        $preferences = collect(NotificationTypes::all())->map(fn (string $type): array => [
            'type' => $type,
            'is_enabled' => $saved->get($type)?->is_enabled,
        ])->values();

        return response()->json(['data' => $preferences]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $userId = $request->user()->id;

        DB::transaction(function () use ($request, $userId): void {
            foreach ($request->validated()['preferences'] as $row) {
                if ($row['is_enabled'] === null) {
                    // Back to following the rule: drop the override entirely.
                    UserNotificationPreference::query()
                        ->where('user_id', $userId)
                        ->where('type', $row['type'])
                        ->delete();

                    continue;
                }

                UserNotificationPreference::updateOrCreate(
                    ['user_id' => $userId, 'type' => $row['type']],
                    ['is_enabled' => $row['is_enabled']],
                );
            }
        });

        return $this->index($request);
    }
}
