<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkStructure;
use App\Models\Concerns\HasAuditColumns;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mined goods reported by the mining engineers — daily entries plus standalone
 * monthly figures. Quantity is stored in the record's own unit (tons by default).
 */
class ProductionRecord extends Model
{
    use BelongsToWorkStructure, HasAuditColumns, HasFactory;

    protected $table = 'evidencija_proizvodnje';

    public const PERIOD_TYPES = ['daily', 'monthly'];

    public const APPROVAL_STATUSES = ['draft', 'approved', 'rejected'];

    /** Canonical material values; the workbook's labels are normalized into these. */
    public const MATERIAL_TYPES = ['bauxite_ore', 'overburden', 'limestone', 'other'];

    public const UNITS = ['tons', 'm3', 'kg'];

    protected $fillable = [
        'period_type',
        'date',
        'period_month',
        'worksite_id',
        'engineer_id',
        'material_type',
        'quantity',
        'unit',
        'quality_grade',
        'attachment_path',
        'source',
        'notes',
    ];

    protected $attributes = [
        'period_type' => 'daily',
        'material_type' => 'bauxite_ore',
        'unit' => 'tons',
        'approval_status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'period_month' => 'date:Y-m-d',
            'quantity' => 'decimal:3',
            'approved_at' => 'datetime',
        ];
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class);
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'engineer_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('period_month', MonthPeriod::normalize($month));
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->whereBetween('period_month', MonthPeriod::yearRange($year));
    }

    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('period_type', 'daily');
    }

    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period_type', 'monthly');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }
}
