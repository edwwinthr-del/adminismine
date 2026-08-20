<?php

namespace App\Models;

use App\Contracts\BooksBankMovement;
use App\Contracts\SettlementLine;
use App\Models\Concerns\BooksMovements;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One social assistance payout to a worker. There is no stored balance here:
 * what is still payable for the year is computed from these rows against the
 * entitlement (see SocialAssistanceService).
 *
 * It is the one settlement with no obligation behind it — the payout *is* the
 * whole record — so it plays both parts of a booking: the reason money moved
 * ({@see BooksBankMovement}) and the line that says it did
 * ({@see SettlementLine}).
 */
class SocialAssistancePayment extends Model implements BooksBankMovement, SettlementLine
{
    use BooksMovements;
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'isplate_socijalne_pomoci';

    public const METHODS = ['cash', 'nlb', 'lovcen', 'other'];

    /** @var list<string> */
    protected array $searchable = ['person_name'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['employee' => ['first_name', 'last_name']];

    protected $fillable = [
        'employee_id',
        'person_name',
        'payment_date',
        'entitlement_year',
        'currency',
        'amount',
        'exchange_rate',
        'exchange_rate_date',
        'amount_eur',
        'method',
        'bank_transaction_id',
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
            'payment_date' => 'date:Y-m-d',
            'exchange_rate_date' => 'date:Y-m-d',
            'entitlement_year' => 'integer',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'amount_eur' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    protected function recipientName(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->employee?->full_name ?? $this->person_name);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('entitlement_year', $year);
    }

    /** Assistance paid to a worker: money out, alongside the travel costs it sits with. */
    public function movementCategory(): string
    {
        return 'travel';
    }

    public function movementDirection(): int
    {
        return -1;
    }

    public function movementDescription(): ?string
    {
        return $this->recipient_name;
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

    public function lineMethod(): ?string
    {
        return $this->method;
    }

    /** Why it was paid, in the operator's own words — never a composed sentence. */
    public function lineReference(): ?string
    {
        return $this->reason;
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
        return $movement->payments()->exists()
            || $movement->socialAssistancePayments()->whereKeyNot($this->getKey())->exists();
    }
}
