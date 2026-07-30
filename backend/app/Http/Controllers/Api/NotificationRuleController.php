<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationRulesRequest;
use App\Http\Resources\NotificationRuleResource;
use App\Models\NotificationRule;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** Admin configuration of the notification rules, plus a manual re-scan. */
class NotificationRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = NotificationRule::query()->orderBy('type')->get();

        return response()->json([
            'data' => NotificationRuleResource::collection($rules),
            'meta' => [
                'types' => NotificationTypes::all(),
                'timings' => NotificationTypes::TIMINGS,
                'severities' => NotificationTypes::SEVERITIES,
                'channels' => NotificationTypes::CHANNELS,
                // Served here so configuring recipients never also requires
                // permission to manage roles.
                'roles' => Role::query()->orderBy('name')->pluck('name'),
            ],
        ]);
    }

    public function update(UpdateNotificationRulesRequest $request): JsonResponse
    {
        $payload = $request->validated()['rules'];

        DB::transaction(function () use ($payload): void {
            foreach ($payload as $row) {
                $rule = NotificationRule::firstOrNew(['type' => $row['type']]);

                $rule->fill([
                    'is_enabled' => $row['is_enabled'],
                    'timing' => $row['timing'],
                    // Only a days_before rule keeps a day count.
                    'days_before' => $row['timing'] === 'days_before' ? $row['days_before'] : null,
                    'severity' => $row['severity'],
                    'channels' => $row['channels'],
                    'recipient_roles' => array_values($row['recipient_roles']),
                    'recipient_user_ids' => array_values(array_map('intval', $row['recipient_user_ids'])),
                ])->save();
            }
        });

        activity()->causedBy($request->user())
            ->withProperties(['types' => array_column($payload, 'type')])
            ->log('notification_rules.updated');

        return $this->index();
    }

    /** Re-run the detectors now instead of waiting for the daily schedule. */
    public function scan(Request $request, NotificationDispatcher $dispatcher): JsonResponse
    {
        $result = $dispatcher->scan();

        activity()->causedBy($request->user())
            ->withProperties(['created' => $result['created'], 'resolved' => $result['resolved']])
            ->log('notifications.scanned');

        return response()->json(['data' => $result]);
    }
}
