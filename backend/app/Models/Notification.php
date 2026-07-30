<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Support\NotificationTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One notification for one user. The message is never stored as text: `type`
 * plus `data` are rendered through the frontend dictionaries, so the same row
 * reads correctly in Serbian, Turkish and English (rule 4).
 */
class Notification extends Model
{
    use HasAuditColumns, HasFactory;

    protected $fillable = [
        'notification_rule_id',
        'user_id',
        'type',
        'severity',
        'subject_type',
        'subject_id',
        'data',
        'due_date',
        'period',
        'dedupe_key',
        'source',
        'notes',
    ];

    protected $attributes = [
        'severity' => 'info',
        'status' => 'unread',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'due_date' => 'date:Y-m-d',
            'period' => 'date:Y-m-d',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Where the frontend should go when the notification is clicked. */
    protected function linkPath(): Attribute
    {
        return Attribute::make(get: function (): string {
            $base = NotificationTypes::link($this->type);

            return $this->subject_id === null ? $base : "{$base}?highlight={$this->subject_id}";
        });
    }

    public function markRead(): void
    {
        if ($this->status === 'unread') {
            $this->forceFill(['status' => 'read', 'read_at' => now()])->save();
        }
    }

    public function dismiss(): void
    {
        $this->forceFill(['status' => 'dismissed', 'dismissed_at' => now()])->save();
    }

    public function resolve(): void
    {
        $this->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    }

    /** Still on the user's plate — not dismissed and not resolved. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', NotificationTypes::OPEN_STATUSES);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('status', 'unread');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
