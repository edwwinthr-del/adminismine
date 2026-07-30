<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StoreReminderRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A user's own notification centre. Every query is scoped to the signed-in user:
 * there is no route here that can read somebody else's notifications.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(NotificationTypes::STATUSES)],
            'type' => ['nullable', Rule::in(NotificationTypes::all())],
            'severity' => ['nullable', Rule::in(NotificationTypes::SEVERITIES)],
        ]);

        $query = Notification::query()->forUser($request->user()->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } elseif (! $request->boolean('include_history')) {
            // The centre shows live notifications unless history is asked for.
            $query->open();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        $query->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $notifications = $query->limit(200)->get();

        return response()->json([
            'data' => NotificationResource::collection($notifications),
            'meta' => $this->counts($request->user()->id),
        ]);
    }

    /** Feeds the bell in the app shell. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->counts($request->user()->id)]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwned($request, $notification);
        $notification->markRead();

        return response()->json(['data' => new NotificationResource($notification->fresh())]);
    }

    public function dismiss(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwned($request, $notification);
        $notification->dismiss();

        return response()->json(['data' => new NotificationResource($notification->fresh())]);
    }

    /** The user says the underlying task is done; the next scan may reopen it if not. */
    public function resolve(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwned($request, $notification);
        $notification->resolve();

        return response()->json(['data' => new NotificationResource($notification->fresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = Notification::query()
            ->forUser($request->user()->id)
            ->unread()
            ->update(['status' => 'read', 'read_at' => now()]);

        return response()->json([
            'data' => $this->counts($request->user()->id),
            'meta' => ['updated' => $updated],
        ]);
    }

    /**
     * Raise a custom reminder. Its title and body are the author's own words and
     * are stored and shown verbatim — nothing here is translated.
     */
    public function storeReminder(StoreReminderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $recipients = $this->resolveReminderRecipients($data, $request->user());

        $created = DB::transaction(function () use ($data, $recipients, $request): array {
            $rows = [];
            // Shared across recipients so the same reminder is one issue, not many.
            $key = 'custom.reminder:manual:'.now()->timestamp.':'.$request->user()->id;

            foreach ($recipients as $user) {
                $rows[] = Notification::create([
                    'user_id' => $user->id,
                    'type' => 'custom.reminder',
                    'severity' => $data['severity'] ?? 'info',
                    'data' => [
                        'title' => $data['title'],
                        'body' => $data['body'] ?? null,
                        'created_by' => $request->user()->name,
                    ],
                    'due_date' => $data['due_date'] ?? null,
                    'dedupe_key' => $key,
                    'source' => 'manual',
                ]);
            }

            return $rows;
        });

        activity()->causedBy($request->user())
            ->withProperties(['recipients' => count($created), 'title' => $data['title']])
            ->log('notification.reminder_created');

        return response()->json([
            'data' => NotificationResource::collection(collect($created)),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, User>
     */
    private function resolveReminderRecipients(array $data, User $author): Collection
    {
        $userIds = $data['user_ids'] ?? [];
        $roles = $data['roles'] ?? [];

        if ($userIds === [] && $roles === []) {
            return collect([$author]);
        }

        return User::query()
            ->where(function ($query) use ($roles, $userIds): void {
                if ($roles !== []) {
                    $query->whereHas('roles', fn ($q) => $q->whereIn('name', $roles));
                }
                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->get();
    }

    /** @return array{unread: int, open: int, critical: int} */
    private function counts(int $userId): array
    {
        return [
            'unread' => Notification::query()->forUser($userId)->unread()->count(),
            'open' => Notification::query()->forUser($userId)->open()->count(),
            'critical' => Notification::query()->forUser($userId)->open()->where('severity', 'critical')->count(),
        ];
    }

    private function assertOwned(Request $request, Notification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
    }
}
