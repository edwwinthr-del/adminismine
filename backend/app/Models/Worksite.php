<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worksite extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    /** @var list<string> */
    protected array $searchable = ['name', 'location'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = [
        'mine' => ['name'],
        'project' => ['name'],
    ];

    protected $fillable = [
        'name',
        'location',
        'mine_id',
        'project_id',
        'client_id',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function mine(): BelongsTo
    {
        return $this->belongsTo(Mine::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_worksite')
            ->withPivot(['assigned_from', 'assigned_to'])
            ->withTimestamps();
    }

    public function masters(): BelongsToMany
    {
        return $this->belongsToMany(Master::class, 'master_worksite')->withTimestamps();
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
