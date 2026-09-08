<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Support\CompanyConfig;
use App\Support\NotificationTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns candidates into notifications: decides who hears about each one, refuses
 * to say the same thing twice, and quietly closes notifications whose reason has
 * gone away (an invoice gets paid, a document gets uploaded).
 */
class NotificationDispatcher
{
    public function __construct(private readonly NotificationScanner $scanner) {}

    /**
     * Run every enabled rule.
     *
     * @return array{created: int, resolved: int, by_type: array<string, array{created: int, resolved: int}>}
     */
    public function scan(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $created = 0;
        $resolved = 0;
        $byType = [];

        $config = app(CompanyConfig::class);

        $rules = NotificationRule::query()
            ->enabled()
            ->whereIn('type', NotificationTypes::scannable())
            ->get()
            // A module this company does not have has nothing to notify about.
            // Existing rows for it are left alone rather than resolved: the
            // module is hidden, not deleted, and re-enabling it should find its
            // notifications where it left them.
            ->filter(fn ($rule): bool => $config->permissionEnabled(NotificationTypes::permission($rule->type)));

        foreach ($rules as $rule) {
            $result = $this->runRule($rule, $today);

            $created += $result['created'];
            $resolved += $result['resolved'];
            $byType[$rule->type] = $result;
        }

        return ['created' => $created, 'resolved' => $resolved, 'by_type' => $byType];
    }

    /** @return array{created: int, resolved: int} */
    public function runRule(NotificationRule $rule, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $candidates = $this->scanner->candidatesFor($rule, $today)
            ->filter(fn (NotificationCandidate $candidate): bool => $rule->isDue($candidate->dueDate, $today))
            ->values();

        if (NotificationTypes::isSummary($rule->timing)) {
            $candidates = $this->collapseToSummary($rule, $candidates, $today);
        }

        $recipients = $this->recipientsFor($rule);

        $liveKeys = $candidates
            ->map(fn (NotificationCandidate $candidate): string => $this->dedupeKey($rule, $candidate))
            ->all();

        $created = 0;

        DB::transaction(function () use ($rule, $candidates, $recipients, $today, &$created): void {
            foreach ($candidates as $candidate) {
                foreach ($recipients as $user) {
                    $created += $this->deliver($rule, $candidate, $user, $today) ? 1 : 0;
                }
            }
        });

        return ['created' => $created, 'resolved' => $this->resolveCleared($rule, $liveKeys)];
    }

    /**
     * Everything a summary rule found, as a single notification carrying the
     * count and total rather than one row per record.
     *
     * @param  Collection<int, NotificationCandidate>  $candidates
     * @return Collection<int, NotificationCandidate>
     */
    private function collapseToSummary(
        NotificationRule $rule,
        Collection $candidates,
        Carbon $today,
    ): Collection {
        if ($candidates->isEmpty()) {
            return collect();
        }

        $period = $rule->periodFor($today);

        return collect([new NotificationCandidate(
            data: [
                'count' => $candidates->count(),
                'total' => round((float) $candidates->sum(
                    fn (NotificationCandidate $candidate): float => $candidate->amount ?? 0.0,
                ), 2),
                'period' => $period?->toDateString(),
            ],
            key: 'period:'.$period?->toDateString(),
        )]);
    }

    /**
     * The rule's recipients, minus anyone who turned this type off for
     * themselves. A user's own preference always wins over the rule.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(NotificationRule $rule): Collection
    {
        $recipients = $rule->recipients();

        if ($recipients->isEmpty()) {
            return $recipients;
        }

        $optedOut = UserNotificationPreference::query()
            ->where('type', $rule->type)
            ->where('is_enabled', false)
            ->whereIn('user_id', $recipients->pluck('id'))
            ->pluck('user_id')
            ->all();

        return $recipients->reject(fn (User $user): bool => in_array($user->id, $optedOut, true))->values();
    }

    /**
     * True when the user was actually alerted — either a new row, or an old one
     * reopened because the issue came back after being resolved.
     *
     * An issue the user dismissed stays dismissed; an issue still on their plate
     * has its numbers refreshed (days overdue move) without nagging them again.
     */
    private function deliver(
        NotificationRule $rule,
        NotificationCandidate $candidate,
        User $user,
        Carbon $today,
    ): bool {
        $key = $this->dedupeKey($rule, $candidate);

        $existing = Notification::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'dismissed') {
                return false;
            }

            $existing->forceFill([
                'data' => $candidate->data,
                'due_date' => $candidate->dueDate?->toDateString(),
                'severity' => $rule->severity,
            ]);

            // The condition came back after it had cleared — alert again.
            $reopened = $existing->status === 'resolved';
            if ($reopened) {
                $existing->forceFill(['status' => 'unread', 'read_at' => null, 'resolved_at' => null]);
            }

            $existing->save();

            return $reopened;
        }

        Notification::create([
            'notification_rule_id' => $rule->id,
            'user_id' => $user->id,
            'type' => $rule->type,
            'severity' => $rule->severity,
            'subject_type' => $candidate->subject === null ? null : $candidate->subject::class,
            'subject_id' => $candidate->subject?->getKey(),
            'data' => $candidate->data,
            'due_date' => $candidate->dueDate?->toDateString(),
            'period' => $rule->periodFor($today)?->toDateString(),
            'dedupe_key' => $key,
            'source' => 'scan',
        ]);

        return true;
    }

    /**
     * Close notifications of this type whose condition no longer holds. This is
     * what makes the bell trustworthy: paying an invoice clears its notification
     * on the next scan instead of leaving the user to tidy up by hand.
     *
     * @param  list<string>  $liveKeys
     */
    private function resolveCleared(NotificationRule $rule, array $liveKeys): int
    {
        $stale = Notification::query()
            ->where('type', $rule->type)
            ->open()
            ->when($liveKeys !== [], fn ($query) => $query->whereNotIn('dedupe_key', $liveKeys))
            ->get();

        foreach ($stale as $notification) {
            $notification->resolve();
        }

        return $stale->count();
    }

    private function dedupeKey(NotificationRule $rule, NotificationCandidate $candidate): string
    {
        return "{$rule->type}:{$candidate->identity()}";
    }
}
