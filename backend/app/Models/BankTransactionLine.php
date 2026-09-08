<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's share of a movement.
 *
 * A movement spans more than one account whenever it is a transfer — cash into
 * the bank is negative on the till and positive on the account, within the same
 * movement — which is exactly what the three columns this replaces allowed.
 *
 * `amount` is signed (+ in, − out) and `amount_eur` is its accounting-currency
 * twin, priced with the rate stored on the movement (rule 5). Both are
 * maintained by {@see BankTransaction::refreshTotals()}: nothing writes a line's
 * EUR figure directly, because the rate belongs to the movement and a line
 * priced with a different one would make the movement's own total disagree with
 * the sum of its parts.
 */
class BankTransactionLine extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'stavke_transakcija';

    protected $fillable = [
        'bank_transaction_id',
        'account_id',
        'amount',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_eur' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'account_id');
    }
}
