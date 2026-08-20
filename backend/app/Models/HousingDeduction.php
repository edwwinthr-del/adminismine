<?php

namespace App\Models;

use App\Models\Concerns\ConvertsToEur;
use App\Models\Concerns\HasAuditColumns;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A housing cost charged to a worker. Rent and bills are company expenses by
 * default (PROJECT_LLM_APP_PROMPT.md "Worker Housing"), so every row here is an
 * explicit exception and always carries a reason.
 */
class HousingDeduction extends Model
{
    use ConvertsToEur;
    use HasAuditColumns, HasFactory;

    protected $table = 'odbici_za_smestaj';

    protected $fillable = [
        'employee_id',
        'house_id',
        'month',
        'currency',
        'exchange_rate',
        'exchange_rate_date',
        'rent_share',
        'utility_share',
        'amount_deducted',
        'reason',
        'utility_bill_id',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'rent_share' => 0,
        'utility_share' => 0,
        'amount_deducted' => 0,
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'exchange_rate_date' => 'date:Y-m-d',
            'rent_share' => 'decimal:2',
            'utility_share' => 'decimal:2',
            'amount_deducted' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'amount_eur' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function utilityBill(): BelongsTo
    {
        return $this->belongsTo(UtilityBill::class);
    }

    /** What the worker is charged in total: the rent share plus the utility share. */
    protected function chargedAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->rent_share + (float) $this->utility_share, 2),
        );
    }

    /** Remaining is derived: what was charged minus what has been deducted. */
    public function recalculate(): void
    {
        $this->forceFill([
            'remaining_amount' => round($this->charged_amount - (float) $this->amount_deducted, 2),
        ])->save();
    }

    /** The charge is two shares together, so that — not one column — is what is priced. */
    protected function eurSourceColumn(): string
    {
        return 'charged_amount';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->month)->toDateString();
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('month', MonthPeriod::normalize($month));
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('remaining_amount', '>', 0);
    }
}
