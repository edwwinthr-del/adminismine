<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'kursevi_valuta';

    protected $fillable = [
        'base_currency',
        'quote_currency',
        'rate',
        'rate_date',
        'provider',
        'is_manual',
        'override_reason',
        'fetched_at',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:10',
            'rate_date' => 'date:Y-m-d',
            'is_manual' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Convert an amount expressed in the quote currency (e.g. TRY) into the
     * base currency (EUR). Convention: 1 base = `rate` quote.
     */
    public function toBase(float|int|string $quoteAmount): float
    {
        return (float) $quoteAmount / (float) $this->rate;
    }

    /**
     * Convert an amount in the base currency (EUR) into the quote currency (TRY).
     */
    public function toQuote(float|int|string $baseAmount): float
    {
        return (float) $baseAmount * (float) $this->rate;
    }

    /**
     * The most recent stored rate for a currency pair (manual overrides win
     * for a given day because they are stored on the same date row).
     */
    public static function latestFor(string $quote, string $base = 'EUR'): ?self
    {
        return static::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->first();
    }
}
