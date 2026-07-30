<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/** One month's rent obligation for a house. Payments are polymorphic, so a rent
 * payment can be matched to a bank/cash movement like any other settlement. */
class RentPayment extends Model
{
    use HasAuditColumns, HasFactory;

    public const COST_BEARERS = ['company', 'workers'];

    protected $fillable = [
        'house_id',
        'month',
        'currency',
        'rent_amount_due',
        'cost_bearer',
        'exception_reason',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'status' => 'unpaid',
        'cost_bearer' => 'company',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'rent_amount_due' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** Recompute paid/remaining/status from the linked payments. */
    public function recalculate(): void
    {
        $due = round((float) $this->rent_amount_due, 2);
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $remaining = round($due - $paid, 2);

        $this->forceFill([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $paid <= 0 ? 'unpaid' : ($remaining > 0.001 ? 'partial' : 'paid'),
        ])->save();
    }

    /** Overdue once the house's rent-due day has passed and it is not settled. */
    protected function isOverdue(): Attribute
    {
        return Attribute::make(get: function (): bool {
            if ($this->status === 'paid') {
                return false;
            }

            $dueDay = $this->house?->rent_due_day;
            $due = $dueDay === null
                ? $this->month->copy()->endOfMonth()
                : $this->month->copy()->addDays(max(0, $dueDay - 1));

            return $due->startOfDay()->lt(now()->startOfDay());
        });
    }

    protected function dueDate(): Attribute
    {
        return Attribute::make(get: function (): Carbon {
            $dueDay = $this->house?->rent_due_day;

            return $dueDay === null
                ? $this->month->copy()->endOfMonth()
                : $this->month->copy()->addDays(max(0, $dueDay - 1));
        });
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('month', MonthPeriod::normalize($month));
    }
}
