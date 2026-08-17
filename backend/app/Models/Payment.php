<?php

namespace App\Models;

use App\Models\Concerns\ConvertsToEur;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
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
        'method',
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

    protected function eurSourceColumn(): string
    {
        return 'amount';
    }

    protected function eurRateDate(): ?string
    {
        return optional($this->payment_date)->toDateString();
    }
}
