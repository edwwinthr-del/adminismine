<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One turn of the conversation, always scoped to the user who had it. */
class AssistantMessage extends Model
{
    use HasFactory;

    public const ROLES = ['user', 'assistant'];

    protected $fillable = [
        'user_id',
        'role',
        'content',
        'intent',
        'data',
        'ai_suggestion_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(AiSuggestion::class, 'ai_suggestion_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
