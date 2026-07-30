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
            return collect();
        }

        return User::query()
            ->where(function (Builder $query) use ($roles, $userIds): void {
                if ($roles !== []) {
                    $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles));
                }
                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->get();
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
