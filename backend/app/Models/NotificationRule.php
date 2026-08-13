<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Support\NotificationTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** How one notification type behaves: whether it fires, when, and for whom. */
class NotificationRule extends Model
{
    use HasAuditColumns, HasFactory;

    protected $fillable = [
        'type',
        'is_enabled',
        'timing',
        'days_before',
        'severity',
        'channels',
        'recipient_roles',
        'recipient_user_ids',
        'config',
        'source',
        'notes',
    ];

    protected $attributes = [
        'is_enabled' => true,
        'timing' => 'same_day',
        'severity' => 'info',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'days_before' => 'integer',
            'channels' => 'array',
            'recipient_roles' => 'array',
            'recipient_user_ids' => 'array',
            'config' => 'array',
        ];
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * The users this rule notifies: everyone holding one of its roles, plus the
     * individually named users. Users who opted out of the type are dropped by
     * the dispatcher, not here.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $roles = $this->recipient_roles ?? [];
        $userIds = $this->recipient_user_ids ?? [];

        if ($roles === [] && $userIds === []) {
            return $this->lastResortRecipients();
        }

        $recipients = $this->permitted(
            User::query()
                // A deactivated account cannot read anything, so writing to it
                // only builds a backlog that greets the person if they are ever
                // restored — and silently inflates the "created" count.
                ->where('is_active', true)
                ->where(function (Builder $query) use ($roles, $userIds): void {
                    if ($roles !== []) {
                        $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles));
                    }
                    if ($userIds !== []) {
                        $query->orWhereIn('id', $userIds);
                    }
                })
                ->get()
        );

        return $recipients->isEmpty() ? $this->lastResortRecipients() : $recipients;
    }

    /**
     * Who to tell when the rule as configured reaches nobody.
     *
     * The seeded rules all name the role `Admin`, while the only account a fresh
     * install creates is a `Super Admin` — and the role query is literal, so
     * `Gate::before` does not help. The result was an app that raised its first
     * notification for nobody and gave no sign why: enter forty overdue invoices,
     * press Re-scan, get `created: 0`, forever. A rule that reaches no one is
     * always a misconfiguration, so it falls back to whoever can fix it.
     *
     * @return Collection<int, User>
     */
    private function lastResortRecipients(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', User::SUPER_ADMIN))
            ->get();
    }

    /**
     * Drop anyone who may not see what the notification would tell them.
     *
     * Recipients were chosen by role name and raw user id, and no permission was
     * ever consulted — so adding a roleless worker to the `payables.overdue`
     * recipients handed him supplier names, invoice numbers and outstanding
     * amounts he is refused in `/payables`. Naming a user is still how you
     * include someone who lacks the *role*; it is not a way to grant them sight
     * of the data, because a role is not a permission (rule 6).
     *
     * Filtered in PHP rather than SQL so it goes through the same `can()` the
     * rest of the app uses — which means a permission granted directly, one
     * inherited from any role, and the Super Admin bypass are all honoured, and
     * this can never drift from what the module itself allows.
     *
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    private function permitted(Collection $candidates): Collection
    {
        $permission = NotificationTypes::permission($this->type);

        if ($permission === null) {
            return $candidates;
        }

        return $candidates->filter(fn (User $user): bool => $user->can($permission))->values();
    }

    /**
     * Whether a candidate due on `$dueDate` is close enough to notify about.
     * Candidates without a due date are always actionable (a missing document
     * is not "due" — it is simply missing).
     */
    public function isDue(?Carbon $dueDate, ?Carbon $today = null): bool
    {
        if ($dueDate === null || NotificationTypes::isSummary($this->timing)) {
            return true;
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $due = $dueDate->copy()->startOfDay();

        return match ($this->timing) {
            'after_due' => $due->lt($today),
            'days_before' => $due->lte($today->copy()->addDays($this->days_before ?? 0)),
            default => $due->lte($today), // same_day
        };
    }

    /** First day of the bucket a summary belongs to, or null for per-record types. */
    public function periodFor(?Carbon $today = null): ?Carbon
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return match ($this->timing) {
            'weekly_summary' => $today->startOfWeek(),
            'monthly_summary' => $today->startOfMonth(),
            default => null,
        };
    }
}
