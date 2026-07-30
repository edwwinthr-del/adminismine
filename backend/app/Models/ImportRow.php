<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One parsed sheet row. `raw` is the cells exactly as read — the original text
 * of a financial record is never rewritten, only mapped alongside.
 */
class ImportRow extends Model
{
    use HasFactory;

    public const ACTIONS = ['create', 'skip'];

    public const STATUSES = ['pending', 'imported', 'skipped', 'failed'];

    protected $fillable = [
        'import_batch_id',
        'sheet_name',
        'row_number',
        'target',
        'raw',
        'mapped',
        'issues',
        'action',
    ];

    protected $attributes = [
        'action' => 'create',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'mapped' => 'array',
            'issues' => 'array',
            'row_number' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /** Rows the committer will act on: wanted, and not already written. */
    public function scopeImportable(Builder $query): Builder
    {
        return $query->where('action', 'create')->where('status', 'pending');
    }

    /** A row whose issues include a possible existing record. */
    public function hasDuplicateWarning(): bool
    {
        foreach ($this->issues ?? [] as $issue) {
            if (($issue['type'] ?? null) === 'duplicate') {
                return true;
            }
        }

        return false;
    }
}
