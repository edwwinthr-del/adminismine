<?php

namespace App\Models;

use App\Models\Concerns\ConvertsToEur;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PayableInvoice extends Model
{
    use ConvertsToEur;
    use HasAuditColumns, HasFactory, Searchable;

    /** @var list<string> */
    protected array $searchable = ['invoice_number', 'description', 'expense_category'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['supplier' => ['name']];

    protected $fillable = [
        'supplier_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'description',
        'expense_category',
        'currency',
        'original_amount',
        'exchange_rate',
        'exchange_rate_date',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'original_amount' => 'decimal:2',
            'amount_eur' => 'decimal:2',
            'exchange_rate_date' => 'date:Y-m-d',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Recompute cached paid/remaining/status from the linked payments.
     * These fields are derived, never set directly by users.
     */
    public function recalculate(): void
    {
        // Both sides in EUR. Comparing a TRY invoice total against EUR payments
        // is what let a 100,000 TRY bill be closed by 100,000 EUR of payments;
        // for an all-EUR invoice these are the same numbers as before.
        $paid = round((float) $this->payments()->sum('amount_eur'), 2);
        $original = round((float) $this->amount_eur, 2);
        $remaining = round($original - $paid, 2);

        $status = $paid <= 0 ? 'unpaid' : ($remaining > 0 ? 'partial' : 'paid');

        $this->forceFill([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $status,
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

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    protected function eurSourceColumn(): string
    {
        return 'original_amount';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->invoice_date)->toDateString();
    }
}
