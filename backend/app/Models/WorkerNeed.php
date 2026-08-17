<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerNeed extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'potrebe_radnika';

    public const TYPES = ['equipment', 'document', 'salary_advance', 'travel', 'housing', 'medical', 'other'];

    public const PRIORITIES = ['low', 'normal', 'urgent'];

    public const STATUSES = ['open', 'in_review', 'resolved', 'rejected'];

    /** Statuses that still need someone to act. */
    public const OPEN_STATUSES = ['open', 'in_review'];

    /** Statuses that belong in the history rather than the working list. */
    public const SETTLED_STATUSES = ['resolved', 'rejected'];

    /** @var list<string> */
    protected array $searchable = ['description'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['employee' => ['first_name', 'last_name'], 'worksite' => ['name']];

    protected $fillable = [
        'employee_id',
        'worksite_id',
        'date',
        'need_type',
        'description',
        'priority',
        'status',
        'assigned_user_id',
        'source',
        'notes',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'resolved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** Still on someone's desk. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Dealt with, one way or the other. The archive is exactly the complement of
     * `open()` — defined here rather than in a controller so the active list and
     * the history can never both show, or both hide, the same need.
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', self::SETTLED_STATUSES);
    }
}
