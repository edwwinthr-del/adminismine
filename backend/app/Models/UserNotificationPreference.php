<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own say over a notification type. `is_enabled` is deliberately
 * nullable: null means "whatever the rule says", so a preference row only ever
 * records a decision the user actually made.
 */
class UserNotificationPreference extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'korisnicka_podesavanja';

    protected $fillable = [
        'user_id',
        'type',
        'is_enabled',
        'channels',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'channels' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
