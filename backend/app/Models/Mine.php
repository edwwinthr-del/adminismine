<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A deposit being worked. Worksites point at it, which is what lets attendance,
 * production and machines be totalled per mine without carrying a `mine_id` of
 * their own.
 */
class Mine extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    /** @var list<string> */
    protected array $searchable = ['name', 'code', 'location', 'material_type'];

    protected $fillable = [
        'name',
        'code',
        'location',
        'material_type',
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

    public function worksites(): HasMany
    {
        return $this->hasMany(Worksite::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
