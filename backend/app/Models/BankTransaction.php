<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use App\Services\CurrencyConverter;
use App\Support\Currencies;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
     * together; {@see refreshTotals()} applies it to every line on every write.
     *
     * The other categories are left alone on purpose: a transfer is negative on
     * one account and positive on another *within the same movement*, and loan,
     * payroll, housing, travel and other legitimately run both ways (a loan
     * received is money in, a repayment is money out).
     */
    public const DIRECTIONS = ['income' => 1, 'expense' => -1];

    /** @var list<string> */
    protected array $searchable = ['description_1', 'description_2'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['supplier' => ['name'], 'client' => ['name']];

    /**
     * `amount`, `amount_eur` and `duplicate_signature` are deliberately absent:
     * they are derived from the lines by {@see refreshTotals()} and there is no
     * way to type them in.
     */
    protected $fillable = [
        'date',
        'description_1',
        'description_2',
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
            'amount' => 'decimal:2',
            'amount_eur' => 'decimal:2',
            'exchange_rate_date' => 'date:Y-m-d',
        ];
    }

    /**
     * Rate resolution happens on the model, not in a form request, so it holds
     * for every way a row is written — the API, the Excel importer, a seeder or
     * a console command. There is no path that can store a movement in a foreign
     * currency without recording what it was priced at.
     */
    protected static function booted(): void
    {
        static::saving(function (self $transaction): void {
            $transaction->resolveExchangeRate();
        });
    }

    /**
     * Pin the rate this movement's lines will be priced with.
     *
     * A movement in the accounting currency converts 1:1 and stores no rate —
     * that null is what tells a later reader the figure was never converted.
     * Anything else keeps the rate it was given, or takes the newest one on or
     * before its date and records it here. Falling back to 1:1 instead is
     * precisely the bug the EUR columns exist to end, and a movement in a
     * currency the app cannot price is not a movement it can record —
     * MissingExchangeRateException renders itself as a 422.
     */
    public function resolveExchangeRate(): void
    {
        $currency = strtoupper((string) ($this->currency ?: Currencies::BASE));

        if ($currency === Currencies::BASE) {
            $this->exchange_rate = null;
            $this->exchange_rate_date = null;

            return;
        }

        if ((float) $this->exchange_rate > 0) {
            return;
        }

        $resolved = app(CurrencyConverter::class)->toEur(
            0,
            $currency,
            null,
            optional($this->date)->toDateString(),
        );

        $this->exchange_rate = (float) $resolved['exchange_rate'];
        $this->exchange_rate_date = $resolved['exchange_rate_date'];
    }

    /** The accounts this movement touched, one line each. */
    public function lines(): HasMany
    {
        return $this->hasMany(BankTransactionLine::class, 'bank_transaction_id');
    }

    /**
     * Replace this movement's lines, then bring its totals back in step.
     *
     * The single write path for amounts: the API, the importer, the settlement
     * booker and the factories all come through here, so a movement whose stored
     * net disagrees with its lines is not a state the app can reach.
     *
     * A zero is not a movement through an account, it is the absence of one, so
     * zero lines are dropped rather than stored — which is what keeps a
     * one-account movement a single line and a transfer two. Two entries naming
     * the same account are summed, since one account holds one share of a
     * movement.
     *
     * @param  list<array{account_id: int|string, amount: float|int|string}>  $lines
     */
    public function setLines(array $lines): void
    {
        $byAccount = [];

        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            $amount = round((float) ($line['amount'] ?? 0), 2);

            if ($accountId === 0) {
                continue;
            }

            $byAccount[$accountId] = round(($byAccount[$accountId] ?? 0.0) + $amount, 2);
        }

        $byAccount = array_filter($byAccount, static fn (float $amount): bool => $amount !== 0.0);

        foreach ($byAccount as $accountId => $amount) {
            $this->lines()->updateOrCreate(
                ['account_id' => $accountId],
                ['amount' => $amount],
            );
        }

        $this->lines()->whereNotIn('account_id', array_keys($byAccount) ?: [0])->delete();

        $this->refreshTotals();
    }

    /**
     * Re-derive everything the movement stores about its own money.
     *
     * Three things are maintained here and nowhere else: each line's sign (from
     * the category's direction), each line's EUR twin (from the movement's own
     * rate — a line priced with a different one would make the movement
     * disagree with the sum of its parts), and the movement's net plus the
     * signature the duplicate flag groups on.
     *
     * The net is stored rather than summed on read because the running balance
     * is a window function over the whole ledger and the duplicate flag is a
     * grouped aggregate; neither can afford a join-and-group per page. It is a
     * derived value kept in step by this method, not an editable figure — no
     * balance is stored anywhere (rule 1).
     */
    public function refreshTotals(): void
    {
        $sign = self::DIRECTIONS[$this->category] ?? null;
        $rate = (float) $this->exchange_rate;
        $converts = strtoupper((string) ($this->currency ?: Currencies::BASE)) !== Currencies::BASE && $rate > 0;

        $amount = 0.0;
        $amountEur = 0.0;
        $signature = [];

        foreach ($this->lines()->orderBy('account_id')->get() as $line) {
            $value = round((float) $line->amount, 2);

            // The magnitude the operator typed is never changed — only its sign.
            if ($sign !== null && $value !== 0.0) {
                $value = $sign * abs($value);
            }

            $eur = $converts ? round($value / $rate, 2) : $value;

            if ((float) $line->amount !== $value || (float) $line->amount_eur !== $eur) {
                $line->forceFill(['amount' => $value, 'amount_eur' => $eur])->saveQuietly();
            }

            $amount += $value;
            $amountEur += $eur;
            $signature[] = $line->account_id.':'.number_format($value, 2, '.', '');
        }

        $this->forceFill([
            'amount' => round($amount, 2),
            'amount_eur' => round($amountEur, 2),
            // What makes two rows "the same movement entered twice": the date
            // plus every account share. Stored so the grouping behind the
            // duplicate flag is an index scan rather than a sort of the table.
            'duplicate_signature' => implode('|', [optional($this->date)->toDateString(), ...$signature]),
        ])->saveQuietly();
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
        return Attribute::make(get: fn (): float => round((float) $this->amount, 2));
    }

    /** The same net in the accounting currency — what every total is built from. */
    protected function netAmountEur(): Attribute
    {
        return Attribute::make(get: fn (): float => round((float) $this->amount_eur, 2));
    }

    protected function isUncategorized(): Attribute
    {
        return Attribute::make(get: fn (): bool => empty($this->category));
    }

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
            ->select('duplicate_signature')
            ->whereNotNull('duplicate_signature')
            ->groupBy('duplicate_signature')
            ->havingRaw('COUNT(*) > 1');

        return static::query()->joinSub(
            $signatures,
            'duplicate_signatures',
            fn ($join) => $join->on(
                "{$table}.duplicate_signature",
                '=',
                'duplicate_signatures.duplicate_signature',
            ),
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
     * Current balance of every account. Shared by the bank page and the
     * dashboard so the two can never quote different balances, and computed as
     * one aggregate rather than a pass per account.
     *
     * Every account is listed, including one nothing has moved through yet: a
     * missing row and a zero balance are different claims, and an account the
     * operator just opened should read as empty rather than not exist.
     *
     * @return array{accounts: list<array{id: int, name: string, kind: string, balance: float}>, total: float}
     */
    public static function accountBalances(): array
    {
        $lines = (new BankTransactionLine)->getTable();
        $accounts = (new BankAccount)->getTable();

        // Summed in EUR: adding a TRY movement's face value to a EUR balance is
        // what made a ~2,600 EUR bill read as 100,000 (rule 5).
        $rows = BankAccount::query()
            ->leftJoin($lines, "{$lines}.account_id", '=', "{$accounts}.id")
            ->groupBy("{$accounts}.id", "{$accounts}.name", "{$accounts}.kind", "{$accounts}.sort_order")
            ->orderBy("{$accounts}.sort_order")
            ->orderBy("{$accounts}.name")
            ->select("{$accounts}.id", "{$accounts}.name", "{$accounts}.kind")
            ->selectRaw("COALESCE(SUM({$lines}.amount_eur), 0) as balance")
            ->get()
            ->map(fn (BankAccount $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'kind' => (string) $row->kind,
                'balance' => round((float) $row->balance, 2),
            ])
            ->all();

        return [
            'accounts' => $rows,
            'total' => round(array_sum(array_column($rows, 'balance')), 2),
        ];
    }

    /**
     * The same balances, as at the end of each of the last `$months` months.
     *
     * Two grouped queries whatever the number of accounts: everything before the
     * window as each account's opening figure, then the movements inside it
     * bucketed by month, run forward in PHP. A per-account query would be an
     * N+1 that grows with the company's bank accounts, and a per-month one an
     * N+1 that grows with the window.
     *
     * These are balances **as at each month end**, so the last point equals the
     * account's balance today only when nothing is dated after this month —
     * which is the honest reading of a history, and the reason the figure beside
     * it is still `accountBalances()` rather than the end of this series.
     *
     * @return array{months: list<string>, accounts: array<int, list<float>>, total: list<float>}
     */
    public static function accountBalanceHistory(string $month, int $months): array
    {
        $lines = (new BankTransactionLine)->getTable();
        $movements = (new self)->getTable();

        $first = Carbon::parse(MonthPeriod::normalize($month))
            ->subMonthsNoOverflow($months - 1)
            ->startOfMonth();
        $last = Carbon::parse(MonthPeriod::end($month));

        $driver = (new self)->getConnection()->getDriverName();
        $bucket = MonthPeriod::sqlMonth($driver, "{$movements}.date");

        // In EUR on both sides, like every other figure the dashboard quotes:
        // a TRY line's face value added to a EUR balance is a total of nothing.
        $opening = BankTransactionLine::query()
            ->join($movements, "{$movements}.id", '=', "{$lines}.bank_transaction_id")
            ->where("{$movements}.date", '<', $first->toDateString())
            ->groupBy("{$lines}.account_id")
            ->select("{$lines}.account_id")
            ->selectRaw("COALESCE(SUM({$lines}.amount_eur), 0) as opening")
            ->pluck('opening', 'account_id');

        $deltas = BankTransactionLine::query()
            ->join($movements, "{$movements}.id", '=', "{$lines}.bank_transaction_id")
            ->whereBetween("{$movements}.date", [$first->toDateString(), $last->toDateString()])
            ->groupBy("{$lines}.account_id")
            ->groupByRaw($bucket)
            ->select("{$lines}.account_id")
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw("COALESCE(SUM({$lines}.amount_eur), 0) as delta")
            ->get()
            ->groupBy('account_id');

        $buckets = [];
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $buckets[] = Carbon::parse(MonthPeriod::normalize($month))
                ->subMonthsNoOverflow($offset)
                ->format('Y-m');
        }

        $ids = BankAccount::query()->orderBy('sort_order')->orderBy('name')->pluck('id');

        $accounts = [];
        $total = array_fill(0, $months, 0.0);

        foreach ($ids as $id) {
            $running = (float) ($opening[$id] ?? 0);
            $byBucket = $deltas->get($id, collect())->keyBy('bucket');

            $series = [];
            foreach ($buckets as $index => $key) {
                $running += (float) ($byBucket->get($key)->delta ?? 0);
                $series[] = round($running, 2);
                $total[$index] += $running;
            }

            $accounts[(int) $id] = $series;
        }

        return [
            'months' => $buckets,
            'accounts' => $accounts,
            'total' => array_map(fn (float $value): float => round($value, 2), $total),
        ];
    }

    /** Movements no settlement points at — nothing has been matched to them yet. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->doesntHave('payments')->doesntHave('socialAssistancePayments');
    }

    /** Movements with a share on this account. */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->whereHas('lines', fn (Builder $line) => $line->where('account_id', $accountId));
    }

    /**
     * Movements with a share on any account of this kind — `cash` or `bank`.
     * A form asking for "the cash payment" should not offer bank rows.
     */
    public function scopeForAccountKind(Builder $query, string $kind): Builder
    {
        return $query->whereHas(
            'lines',
            fn (Builder $line) => $line->whereHas('account', fn (Builder $a) => $a->where('kind', $kind)),
        );
    }

    /**
     * Add `running_balance`: the balance of every account as of each row, in
     * ledger order (oldest first, id breaking ties on the same day).
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

        $ledger = static::query()
            ->select("{$table}.*")
            ->selectRaw(
                // Accumulated in EUR, so a ledger holding more than one currency
                // still reads as a single running balance.
                "SUM({$table}.amount_eur) OVER (ORDER BY {$table}.date, {$table}.id "
                .'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_balance',
            );

        return $query->fromSub($ledger, $table);
    }
}
