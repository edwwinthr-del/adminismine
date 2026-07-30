<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkStructure;
use App\Models\Concerns\HasAuditColumns;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use BelongsToWorkStructure, HasAuditColumns, HasFactory;

    public const STATUSES = ['present', 'absent', 'holiday', 'sick_leave', 'unpaid_leave', 'other'];

    /** Statuses that earn the daily rate. Everything else earns nothing. */
    public const PAID_STATUSES = ['present', 'holiday'];

    public const APPROVAL_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    protected $fillable = [
        'date',
        'employee_id',
        'worksite_id',
        'master_id',
        'status',
        'regular_hours',
        'overtime_hours',
        'overtime_reason',
        'note',
        'source',
        'notes',
    ];

    protected $attributes = [
        'status' => 'present',
        'overtime_hours' => 0,
        'approval_status' => 'draft',
        'currency' => 'EUR',
        'regular_amount' => 0,
        'overtime_amount' => 0,
        'adjustment_amount' => 0,
        'total_amount' => 0,
        'approved_for_payroll' => false,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'regular_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'regular_amount' => 'decimal:2',
            'overtime_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'working_days_basis' => 'integer',
            'approved_for_payroll' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function scopeForDay(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereBetween('date', MonthPeriod::range($month));
    }

    /** Only approved days may feed payroll. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }
}
