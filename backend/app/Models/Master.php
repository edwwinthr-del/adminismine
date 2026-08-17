<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A supervisor who records daily worksite activity. `user_id` is the login used
 * from the field; without it the office enters attendance on their behalf.
 */
class Master extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'majstori';

    /** A master is identified by the worker behind them, so that is what is searched. */
    protected array $searchableRelations = ['employee' => ['first_name', 'last_name']];

    protected $fillable = [
        'employee_id',
        'user_id',
        'is_active',
        'source',
        'notes',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worksites(): BelongsToMany
    {
        return $this->belongsToMany(Worksite::class, 'majstor_gradiliste')->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
