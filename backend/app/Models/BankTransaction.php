<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use App\Services\CurrencyConverter;
use App\Support\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class BankTransaction extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'bankovne_transakcije';

    /** Canonical movement categories — stored as-is, translated only for display (rule 4). */
    public const CATEGORIES = ['income', 'expense', 'transfer', 'loan', 'payroll', 'housing', 'travel', 'other'];

    /**
     * The categories that state a direction, and the sign an amount must carry
     * to mean it.
     *
     * Direction in this table is the **sign of the amount**, not the category:
     * `net_amount`, every balance and the dashboard's income/expense split all
     * read the sign and nothing else. Category was only ever a label, so nothing
     * stopped an expense being typed as +300 — and a positive "expense" is added
     * to the balance and to income, which is exactly the "expenses are added
     * instead of subtracted" symptom. Below is the one place the two are tied
     * together; {@see normalizeAmountSigns()} applies it on every save.
     *
     * The other categories are left alone on purpose: a transfer is negative on
     * one account and positive on another *within the same row*, and loan,
     * payroll, housing, travel and other legitimately run both ways (a loan
     * received is money in, a repayment is money out).
     */
    public const DIRECTIONS = ['income' => 1, 'expense' => -1];

    /** The three account columns whose signed sum is the movement. */
    public const AMOUNT_COLUMNS = ['cash_amount', 'nlb_amount', 'lovcen_amount'];

    /**
     * The EUR twin of each account column, in the same order.
     *
     * Every balance and every dashboard figure sums these, never the originals:
     * a movement is entered in the currency it happened in, and the accounting
     * currency is EUR (rule 5). The originals are what the operator typed and
     * are never rewritten.
     *
     * @var array<string, string>
     */
    public const EUR_COLUMNS = [
        'cash_amount' => 'cash_amount_eur',
        'nlb_amount' => 'nlb_amount_eur',
        'lovcen_amount' => 'lovcen_amount_eur',
    ];

    /** @var list<string> */
    protected array $searchable = ['description_1', 'description_2'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['supplier' => ['name'], 'client' => ['name']];

    protected $fillable = [
        'date',
        'description_1',
        'description_2',
        'cash_amount',
        'nlb_amount',
        'lovcen_amount',
        'category',
        'supplier_id',
        'client_id',
        'currency',
        'exchange_rate',
        'exchange_rate_date',
        'import_source',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'cash_amount' => 'decimal:2',
            'nlb_amount' => 'decimal:2',
            'lovcen_amount' => 'decimal:2',
            'exchange_rate_date' => 'date:Y-m-d',
            'cash_amount_eur' => 'decimal:2',
            'nlb_amount_eur' => 'decimal:2',
            'lovcen_amount_eur' => 'decimal:2',
        ];
    }

    /**
     * Normalisation happens on the model, not in a form request, so it holds for
     * every way a row is written — the API, the Excel importer, a seeder or a
     * console command. There is no path that can store a movement whose sign
     * contradicts its category.
     */
    protected static function booted(): void
    {
        static::saving(function (self $transaction): void {
            $transaction->normalizeAmountSigns();
            $transaction->syncEurAmounts();
        });
    }

    /**
     * Keep the EUR columns in step with the amounts and the stored rate.
     *
     * On the model rather than in a controller, for the same reason the sign
     * normalisation is: the importer, a seeder and a console command all write
     * movements too, and an EUR column that only the API maintained would be
     * silently wrong for every other path. A row in the accounting currency
     * converts 1:1 and stores no rate — that null is what tells a later reader
     * the figure was never converted.
     */
    public function syncEurAmounts(): void
    {
        $currency = strtoupper((string) ($this->currency ?: Currencies::BASE));

        if ($currency === Currencies::BASE) {
            // Already the accounting currency: 1:1, and no rate is stored, which
            // is how a later reader knows nothing was converted.
            $this->exchange_rate = null;
            $this->exchange_rate_date = null;

            foreach (self::EUR_COLUMNS as $column => $eurColumn) {
                $this->{$eurColumn} = round((float) $this->{$column}, 2);
            }

            return;
        }

        $rate = (float) $this->exchange_rate;

        if ($rate <= 0) {
            // No rate pinned on the row, so use the newest one on or before its
            // date and record it here. Falling back to 1:1 instead is precisely
            // the bug this column set exists to end, and a movement in a
            // currency the app cannot price is not a movement it can record —
            // MissingExchangeRateException renders itself as a 422.
            $resolved = app(CurrencyConverter::class)->toEur(
                0,
                $currency,
                null,
                optional($this->date)->toDateString(),
            );

            $rate = (float) $resolved['exchange_rate'];
            $this->exchange_rate = $rate;
            $this->exchange_rate_date = $resolved['exchange_rate_date'];
        }

        foreach (self::EUR_COLUMNS as $column => $eurColumn) {
            $this->{$eurColumn} = round(((float) $this->{$column}) / $rate, 2);
        }
    }

    /**
     * Force the account amounts to agree with the category's direction.
     *
     * Zeroes are left as they are (an untouched account is not a direction), and
     * the magnitude the operator typed is never changed — only its sign.
     */
    public function normalizeAmountSigns(): void
    {
        $sign = self::DIRECTIONS[$this->category] ?? null;

        if ($sign === null) {
            return;
        }

        foreach (self::AMOUNT_COLUMNS as $column) {
            $amount = (float) $this->{$column};

            if ($amount !== 0.0) {
                $this->{$column} = $sign * abs($amount);
            }
        }
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Settlement lines this movement settles (booked or matched). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_transaction_id');
    }

    /**
     * Social assistance payouts pointing at this movement. They settle nothing
     * else — the payout *is* the settlement — so they carry the link themselves
     * instead of going through `payments`, and every question about who claims a
     * movement has to ask both tables.
     */
    public function socialAssistancePayments(): HasMany
    {
        return $this->hasMany(SocialAssistancePayment::class, 'bank_transaction_id');
    }

    /** The movement's net in the currency it was entered in. */
    protected function netAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                (float) $this->cash_amount + (float) $this->nlb_amount + (float) $this->lovcen_amount,
                2,
            ),
        );
    }

    /** The same net in the accounting currency — what every total is built from. */
    protected function netAmountEur(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                (float) $this->cash_amount_eur + (float) $this->nlb_amount_eur + (float) $this->lovcen_amount_eur,
                2,
            ),
        );
    }

    protected function isUncategorized(): Attribute
    {
        return Attribute::make(get: fn (): bool => empty($this->category));
    }

    /**
     * What makes two rows "the same movement entered twice": the date plus all
     * three account amounts. Built PHP-side so the casts are consistent.
     */
    public function duplicateSignature(): string
    {
        return implode('|', [
            optional($this->date)->toDateString(),
            (float) $this->cash_amount,
            (float) $this->nlb_amount,
            (float) $this->lovcen_amount,
        ]);
    }

    /** The columns that together make up {@see duplicateSignature()}. */
    private const SIGNATURE_COLUMNS = ['date', 'cash_amount', 'nlb_amount', 'lovcen_amount'];

    /**
     * Every movement that shares a signature with another, as a query. One
     * definition, used by both the bank list's flag and the dashboard's
     * duplicate alert — the two must never disagree about what a duplicate is.
     *
     * The grouping is done by the database rather than by hydrating the table
     * into PHP: this runs on every bank list page and on every dashboard load,
     * so it has to stay an aggregate, not a full read.
     *
     * @return Builder<self>
     */
    public static function duplicates(): Builder
    {
        $table = (new self)->getTable();

        $signatures = static::query()
            ->select(self::SIGNATURE_COLUMNS)
            ->groupBy(self::SIGNATURE_COLUMNS)
            ->havingRaw('COUNT(*) > 1');

        return static::query()->joinSub(
            $signatures,
            'duplicate_signatures',
            function ($join) use ($table): void {
                foreach (self::SIGNATURE_COLUMNS as $column) {
                    $join->on("{$table}.{$column}", '=', "duplicate_signatures.{$column}");
                }
            },
        )->select("{$table}.*");
    }

    /**
     * @param  list<int>|null  $among  restrict the result to these ids — the page
     *                                 being rendered — without narrowing what
     *                                 counts as a duplicate (still table-wide).
     * @return Collection<int, int>
     */
    public static function duplicateIds(?array $among = null): Collection
    {
        $table = (new self)->getTable();

        return static::duplicates()
            ->when($among !== null, fn (Builder $query) => $query->whereIn("{$table}.id", $among ?? []))
            ->pluck("{$table}.id");
    }

    /** How many movements look like a repeat of another. */
    public static function duplicateCount(): int
    {
        return static::duplicates()->count();
    }

    /**
     * Current balance of each account. Shared by the bank page and the
     * dashboard so the two can never quote different balances, and computed as
     * one aggregate rather than three passes over the table.
     *
     * @return array{cash: float, nlb: float, lovcen: float, total: float}
     */
    public static function accountBalances(): array
    {
        // Summed in EUR: adding a TRY movement's face value to a EUR balance is
        // what made a ~2,600 EUR bill read as 100,000 (rule 5).
        $totals = static::query()->selectRaw(
            'COALESCE(SUM(cash_amount_eur), 0) as cash, COALESCE(SUM(nlb_amount_eur), 0) as nlb, '
            .'COALESCE(SUM(lovcen_amount_eur), 0) as lovcen',
        )->first();

        $cash = round((float) $totals->cash, 2);
        $nlb = round((float) $totals->nlb, 2);
        $lovcen = round((float) $totals->lovcen, 2);

        return [
            'cash' => $cash,
            'nlb' => $nlb,
            'lovcen' => $lovcen,
            'total' => round($cash + $nlb + $lovcen, 2),
        ];
    }

    /** Movements no settlement points at — nothing has been matched to them yet. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->doesntHave('payments')->doesntHave('socialAssistancePayments');
    }

    /**
     * Add `running_balance`: the balance of all three accounts as of each row,
     * in ledger order (oldest first, id breaking ties on the same day).
     *
     * The cumulative sum is computed by a window function over the *whole*
     * table inside a subquery, and the page's filters are applied outside it.
     * That matters: a running balance is only meaningful against the complete
     * ledger, so filtering to one category must not make the column read as
     * though the other movements never happened. The alias is the table's own
     * name, so every existing filter, the search scope and the relation
     * subqueries keep resolving unchanged.
     *
     * It stays one query — the alternative, walking rows in PHP to accumulate,
     * would have to read the whole table to render a single page.
     */
    public function scopeWithRunningBalance(Builder $query): Builder
    {
        $table = $this->getTable();
        // Accumulated in EUR, so a ledger holding more than one currency still
        // reads as a single running balance.
        $net = implode(' + ', array_values(self::EUR_COLUMNS));

        $ledger = static::query()
            ->select("{$table}.*")
            ->selectRaw(
                "SUM({$net}) OVER (ORDER BY {$table}.date, {$table}.id "
                .'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_balance',
            );

        return $query->fromSub($ledger, $table);
    }
}
