<?php

namespace App\Models;

use App\Models\Concerns\ConvertsToEur;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offset against a receivable — work deducted, a credit note, a settlement in
 * kind. It settles the invoice without money moving, which is why it is not a
 * payment and never books a bank movement.
 *
 * It carries its own currency for the same reason every other money row does: an
 * offset agreed in TRY against a TRY invoice is not a EUR figure, and treating
 * it as one silently overstates what has been settled (rule 5).
 */
class ReceivableDeduction extends Model
{
    use ConvertsToEur;
    use HasAuditColumns, HasFactory;

    protected $table = 'odbici_izlaznih_faktura';

    protected $fillable = [
        'receivable_invoice_id',
        'amount',
        'currency',
        'exchange_rate',
        'exchange_rate_date',
        'deduction_date',
        'reason',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
    ];

    protected function casts(): array
    {
        return [
            'deduction_date' => 'date:Y-m-d',
            'exchange_rate_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'amount_eur' => 'decimal:2',
        ];
    }

    public function receivableInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivableInvoice::class);
    }

    protected function eurSourceColumn(): string
    {
        return 'amount';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->deduction_date)->toDateString();
    }
}
