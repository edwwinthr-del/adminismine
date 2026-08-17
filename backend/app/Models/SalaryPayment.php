<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One monthly salary obligation per employee. Amounts paid are recorded as
 * polymorphic payments so bank/cash movements can be linked to them.
 */
class SalaryPayment extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'isplate_zarada';

    protected $fillable = [
        'employee_id',
        'salary_month',
        'currency',
        'base_salary',
        'adjustments',
        'deductions',
        'attachment_path',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'adjustments' => 0,
        'deductions' => 0,
        'status' => 'unpaid',
    ];

    protected function casts(): array
    {
        return [
            'salary_month' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
            'adjustments' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary_due' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Recompute net due / paid / remaining / status. Derived fields are never
     * written directly by users.
     */
    public function recalculate(): void
    {
        $net = round(
            (float) $this->base_salary + (float) $this->adjustments - (float) $this->deductions,
            2,
        );
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $remaining = round($net - $paid, 2);

        $status = $paid <= 0 ? 'unpaid' : ($remaining > 0.001 ? 'partial' : 'paid');

        $this->forceFill([
            'net_salary_due' => $net,
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $status,
        ])->save();
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    /** @param  string  $month  'YYYY-MM' or any date inside the month */
    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('salary_month', self::normalizeMonth($month));
    }

    /** First day of the month for 'YYYY-MM' or 'YYYY-MM-DD' input. */
    public static function normalizeMonth(string $month): string
    {
        return MonthPeriod::normalize($month);
    }
}
