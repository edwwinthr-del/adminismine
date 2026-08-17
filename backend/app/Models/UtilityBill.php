<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasFileAttachments;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class UtilityBill extends Model
{
    use HasAuditColumns, HasFactory, HasFileAttachments;

    protected $table = 'rezijski_racuni';

    public const TYPES = ['electricity', 'water', 'internet', 'heating', 'garbage', 'maintenance', 'other'];

    public const COST_BEARERS = ['company', 'workers'];

    protected $fillable = [
        'house_id',
        'bill_type',
        'billing_period',
        'amount',
        'currency',
        'due_date',
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
            'billing_period' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'paid_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
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

    /** Worker charges created from this bill (only via the explicit split exception). */
    public function housingDeductions(): HasMany
    {
        return $this->hasMany(HousingDeduction::class);
    }

    /** Recompute paid/remaining/status and the settlement date from the payments. */
    public function recalculate(): void
    {
        $amount = round((float) $this->amount, 2);
        $payments = $this->payments()->get();
        $paid = round((float) $payments->sum('amount'), 2);
        $remaining = round($amount - $paid, 2);
        $status = $paid <= 0 ? 'unpaid' : ($remaining > 0.001 ? 'partial' : 'paid');

        $this->forceFill([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $status,
            'paid_date' => $status === 'paid'
                ? optional($payments->max('payment_date'))->toDateString()
                : null,
        ])->save();
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->due_date !== null
                && $this->status !== 'paid'
                && $this->due_date->startOfDay()->lt(now()->startOfDay()),
        );
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('billing_period', MonthPeriod::normalize($month));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }
}
