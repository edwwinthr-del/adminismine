<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A change the assistant proposed. It is only ever a proposal: the record is
 * written when — and only when — the user confirms it.
 */
class AiSuggestion extends Model
{
    use HasAuditColumns, HasFactory;

    public const STATUSES = ['pending', 'confirmed', 'rejected', 'invalid'];

    protected $fillable = [
        'user_id',
        'kind',
        'target',
        'proposed',
        'validated',
        'errors',
        'prompt',
        'source',
        'notes',
    ];

    protected $attributes = [
        'kind' => 'create_record',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'proposed' => 'array',
            'validated' => 'array',
            'errors' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /** Only a validated, still-pending suggestion may be applied. */
    public function isApplicable(): bool
    {
        return $this->status === 'pending' && $this->validated !== null;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
