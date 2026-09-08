<?php

namespace App\Models;

use App\Contracts\SettlementLine;
use App\Models\Concerns\ConvertsToEur;
use App\Models\Concerns\HasAuditColumns;
use App\Services\PaymentBankMovement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line settling something: an invoice, rent, a utility bill, a salary, a
 * ticket, a travel expense or a loan. All of them morph here, which is what lets
 * any settlement be booked into — or matched against — the bank ledger through
 * the same service ({@see PaymentBankMovement}).
 */
class Payment extends Model implements SettlementLine
{
    use ConvertsToEur;
    use HasAuditColumns, HasFactory;

    protected $table = 'placanja';

    protected $fillable = [
        'amount',
        'currency',
        'exchange_rate',
        'exchange_rate_date',
        'payment_date',
        'account_id',
        'bank_transaction_id',
        'reference',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'amount_eur' => 'decimal:2',
            'exchange_rate_date' => 'date:Y-m-d',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** The account the money moved through, or none. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'account_id');
    }

    protected function eurSourceColumn(): string
    {
        return 'amount';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->payment_date)->toDateString();
    }

    public function lineDate(): ?string
    {
        return optional($this->payment_date)->toDateString();
    }

    public function lineAmount(): float
    {
        return (float) $this->amount;
    }

    public function lineCurrency(): ?string
    {
        return $this->currency;
    }

    public function lineExchangeRate(): int|float|string|null
    {
        return $this->exchange_rate;
    }

    public function lineExchangeRateDate(): ?string
    {
        return optional($this->exchange_rate_date)->toDateString();
    }

    public function lineAccountId(): ?int
    {
        return $this->account_id === null ? null : (int) $this->account_id;
    }

    public function lineReference(): ?string
    {
        return $this->reference;
    }

    public function linkedMovementId(): ?int
    {
        return $this->bank_transaction_id === null ? null : (int) $this->bank_transaction_id;
    }

    public function linkMovement(?int $movementId): void
    {
        $this->update(['bank_transaction_id' => $movementId]);
    }

    public function movementHasOtherClaims(BankTransaction $movement): bool
    {
        return $movement->payments()->whereKeyNot($this->getKey())->exists()
            || $movement->socialAssistancePayments()->exists();
    }
}
