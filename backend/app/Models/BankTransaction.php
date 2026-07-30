<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
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

    /** Canonical movement categories — stored as-is, translated only for display (rule 4). */
    public const CATEGORIES = ['income', 'expense', 'transfer', 'loan', 'payroll', 'housing', 'travel', 'other'];

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
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Invoice payments settled by this movement (match links). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_transaction_id');
    }

    protected function netAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                (float) $this->cash_amount + (float) $this->nlb_amount + (float) $this->lovcen_amount,
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
        $totals = static::query()->selectRaw(
            'COALESCE(SUM(cash_amount), 0) as cash, COALESCE(SUM(nlb_amount), 0) as nlb, '
            .'COALESCE(SUM(lovcen_amount), 0) as lovcen',
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

    /** Movements no payment points at — nothing has been matched to them yet. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->doesntHave('payments');
    }
}
