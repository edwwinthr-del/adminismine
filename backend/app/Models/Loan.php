<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A loan or advance (North-Ex and anything like it). Repayments are ordinary
 * polymorphic `payments` rows, so they can be matched to bank/cash movements
 * like every other settlement in the app; the remaining balance is always
 * recomputed from them and never edited directly.
 */
class Loan extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'pozajmice';

    /** `received` = the company owes it back, `given` = the company is owed. */
    public const DIRECTIONS = ['received', 'given'];

    /** Canonical statuses; the workbook's VRACENO ("returned") maps to `repaid`. */
    public const STATUSES = ['outstanding', 'partial', 'repaid'];

    /** @var list<string> */
    protected array $searchable = ['counterparty', 'reference_number'];

    protected $fillable = [
        'counterparty',
        'direction',
        'reference_number',
        'loan_date',
        'due_date',
        'currency',
        'original_amount',
        'exchange_rate',
        'exchange_rate_date',
        'amount_eur',
        'supplier_id',
        'client_id',
        'employee_id',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'direction' => 'received',
        'status' => 'outstanding',
    ];

    protected function casts(): array
    {
        return [
            'loan_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'exchange_rate_date' => 'date:Y-m-d',
            'original_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'amount_eur' => 'decimal:2',
            'repaid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    /** Repayment history, newest first. */
    public function repayments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderByDesc('payment_date');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Recompute repaid/remaining/status in EUR from the repayment history. */
    public function recalculate(): void
    {
        $due = round((float) $this->amount_eur, 2);
        $repaid = round((float) $this->repayments()->sum('amount'), 2);
        $remaining = round($due - $repaid, 2);

        $this->forceFill([
            'repaid_amount' => $repaid,
            'remaining_amount' => $remaining,
            'status' => $repaid <= 0 ? 'outstanding' : ($remaining > 0.001 ? 'partial' : 'repaid'),
        ])->save();
    }

    protected function isOverdue(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->due_date !== null
                && $this->status !== 'repaid'
                && $this->due_date->startOfDay()->lt(now()->startOfDay()),
        );
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['outstanding', 'partial']);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }
}
