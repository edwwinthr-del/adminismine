<?php

namespace App\Models;

use App\Contracts\BooksBankMovement;
use App\Models\Concerns\BooksMovements;
use App\Models\Concerns\ConvertsToEur;
use App\Models\Concerns\HasAuditColumns;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One monthly salary obligation per employee. Amounts paid are recorded as
 * polymorphic payments, so paying a worker books — or matches — a bank/cash
 * movement exactly as settling an invoice does.
 *
 * The obligation is stated in the currency the wage is agreed in;
 * `amount_eur` is the net due in the accounting currency and is what the
 * cross-worker totals sum (rule 5).
 */
class SalaryPayment extends Model implements BooksBankMovement
{
    use BooksMovements;
    use ConvertsToEur;
    use HasAuditColumns, HasFactory;

    protected $table = 'isplate_zarada';

    protected $fillable = [
        'employee_id',
        'salary_month',
        'currency',
        'base_salary',
        'adjustments',
        'deductions',
        'exchange_rate',
        'exchange_rate_date',
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
            'exchange_rate_date' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
            'adjustments' => 'decimal:2',
            'deductions' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'net_salary_due' => 'decimal:2',
            'amount_eur' => 'decimal:2',
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

        // The net is what gets priced, and it is only known here — so the EUR
        // twin is refreshed before it is read, rather than being left a save
        // behind whenever an adjustment or a deduction changes.
        $this->forceFill(['net_salary_due' => $net]);
        $this->syncEurAmount();

        $due = round((float) $this->amount_eur, 2);
        $paid = round((float) $this->payments()->sum('amount_eur'), 2);
        $remaining = round($due - $paid, 2);

        $status = $paid <= 0 ? 'unpaid' : ($remaining > 0.001 ? 'partial' : 'paid');

        $this->forceFill([
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

    /** Wages leaving for a worker: money out, under the payroll category. */
    public function movementCategory(): string
    {
        return 'payroll';
    }

    public function movementDirection(): int
    {
        return -1;
    }

    /** The worker's name — a worker is not a registered supplier or client. */
    public function movementDescription(): ?string
    {
        return $this->employee?->full_name;
    }

    protected function eurSourceColumn(): string
    {
        return 'net_salary_due';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->salary_month)->toDateString();
    }

    /** First day of the month for 'YYYY-MM' or 'YYYY-MM-DD' input. */
    public static function normalizeMonth(string $month): string
    {
        return MonthPeriod::normalize($month);
    }
}
