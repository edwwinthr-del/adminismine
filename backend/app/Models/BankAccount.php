<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use App\Support\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account money moves through: a till, a bank account.
 *
 * These were three columns on `bankovne_transakcije` — `cash_amount`,
 * `nlb_amount`, `lovcen_amount` — which put two Montenegrin bank names into the
 * schema and made a fourth account a migration rather than a row.
 *
 * Accounts are deactivated, never deleted, for the same reason users are (rule
 * 3): a closed account still has to name itself on every movement and every
 * settlement it ever carried. The foreign keys pointing here are
 * `restrictOnDelete` so that holds at the database level too.
 */
class BankAccount extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'bankovni_racuni';

    /** Canonical account kinds — stored as-is, translated only for display (rule 4). */
    public const KINDS = ['cash', 'bank'];

    /** @var list<string> */
    protected array $searchable = ['name', 'iban'];

    protected $fillable = [
        'name',
        'kind',
        'currency',
        'iban',
        'is_active',
        'sort_order',
        'source',
        'notes',
    ];

    protected $attributes = [
        'kind' => 'bank',
        'currency' => Currencies::BASE,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Movement lines booked against this account. */
    public function lines(): HasMany
    {
        return $this->hasMany(BankTransactionLine::class, 'account_id');
    }

    /** Settlement lines that named this account. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'account_id');
    }

    public function socialAssistancePayments(): HasMany
    {
        return $this->hasMany(SocialAssistancePayment::class, 'account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The order every list and dropdown shows accounts in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Open account names, for the places that have to offer a list of them —
     * the import template's enum column, and the validation of an uploaded file
     * against it.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return static::query()->active()->ordered()->pluck('name')->all();
    }

    /** Whether anything at all points at this account. */
    public function hasHistory(): bool
    {
        return $this->lines()->exists()
            || $this->payments()->exists()
            || $this->socialAssistancePayments()->exists();
    }
}
