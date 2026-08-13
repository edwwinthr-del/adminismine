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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReceivableInvoice extends Model
{
    use ConvertsToEur;
    use HasAuditColumns, HasFactory, Searchable;

    /** @var list<string> */
    protected array $searchable = ['invoice_number', 'description'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['client' => ['name']];

    protected $fillable = [
        'client_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'description',
        'currency',
        'invoice_amount',
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
            'invoice_amount' => 'decimal:2',
            'amount_eur' => 'decimal:2',
            'exchange_rate_date' => 'date:Y-m-d',
            'received_amount' => 'decimal:2',
            'deducted_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(ReceivableDeduction::class);
    }

    /**
     * A receivable is settled by payments received and/or deductions/offsets.
     * All three cached fields are derived, never set directly.
     */
    public function recalculate(): void
    {
        // Both sides in EUR — see PayableInvoice::recalculate().
        $received = round((float) $this->payments()->sum('amount_eur'), 2);
        $deducted = round((float) $this->deductions()->sum('amount'), 2);
        $invoice = round((float) $this->amount_eur, 2);
        $remaining = round($invoice - $received - $deducted, 2);

        $settled = $received + $deducted;
        $status = $settled <= 0 ? 'unpaid' : ($remaining > 0 ? 'partial' : 'paid');

        $this->forceFill([
            'received_amount' => $received,
            'deducted_amount' => $deducted,
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
        return 'invoice_amount';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->invoice_date)->toDateString();
    }
}
