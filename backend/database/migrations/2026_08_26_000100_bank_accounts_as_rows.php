<?php

use App\Support\Currencies;
use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank and cash accounts become rows instead of three hardcoded columns.
 *
 * `cash_amount` / `nlb_amount` / `lovcen_amount` named two Montenegrin banks in
 * the schema itself, so every balance, every settlement method, every import
 * template and eight frontend pages knew those three names. A company banking
 * anywhere else could not record a movement at all, and a fourth account here
 * would have meant a column, a migration and an edit in forty files.
 *
 * The shape that replaces it keeps what the columns actually meant: a movement
 * still spans more than one account in a single row — that is what a transfer
 * is — so the amounts move to a line per account, and the movement keeps the
 * net of its lines. Nothing about direction, currency or matching changes.
 *
 * `placanja.method` and `isplate_socijalne_pomoci.method` went the same way:
 * they never described *how* money moved, only *which of the three accounts* it
 * moved through, so they become a nullable account reference. Null is what
 * `other` meant — a settlement that moved no money through an account and can
 * therefore book no movement.
 */
return new class extends Migration
{
    /** The legacy column set, in the order the accounts are seeded. */
    private const LEGACY = [
        'cash_amount' => ['name' => 'Cash', 'kind' => 'cash', 'method' => 'cash'],
        'nlb_amount' => ['name' => 'NLB', 'kind' => 'bank', 'method' => 'nlb'],
        'lovcen_amount' => ['name' => 'Lovćen', 'kind' => 'bank', 'method' => 'lovcen'],
    ];

    /**
     * Accounts opened so far, by name — so a table of ten thousand movements
     * does not ask for the same three ids ten thousand times.
     *
     * @var array<string, int>
     */
    private array $accounts = [];

    public function up(): void
    {
        $this->createAccounts();
        $this->createLines();
        $this->addMovementTotals();
        $this->backfillLines();
        $this->dropLegacyColumns();
        $this->settlementAccounts();
    }

    /**
     * The account catalogue.
     *
     * `is_active` rather than deletion, for the same reason users are
     * deactivated and not deleted (rule 3): a closed account still has to name
     * itself on every movement it ever carried.
     */
    private function createAccounts(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::create('bankovni_racuni', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('kind')->default('bank'); // cash|bank
            $table->string('currency', 3)->default(Currencies::BASE);
            $table->string('iban')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->auditColumns();
            $table->timestamps();
            $table->index('is_active');
        });

        // A cash till is universal, so every install gets one. The two banks
        // are this company's, and are opened only if something actually names
        // them — see accountId(). A fresh database belongs to whoever is
        // installing it, and should not arrive holding two Montenegrin banks.
        $this->accountId('Cash', 'cash');
    }

    /**
     * The account with this name, opened if it does not exist yet.
     *
     * Resolved on demand rather than seeded up front: only the rows actually
     * being migrated can say which of the three accounts this company ever
     * used, and asking the movements table beforehand is what broke the first
     * attempt — `Schema::hasTable()` looks in the *first schema on the
     * search_path*, which during this migration is `sifarnici`, so it answered
     * "no movements table" on Postgres while answering correctly on SQLite,
     * which has no schemas at all.
     */
    private function accountId(string $name, string $kind): int
    {
        if (isset($this->accounts[$name])) {
            return $this->accounts[$name];
        }

        $table = DbSchema::qualify('bankovni_racuni');

        $existing = DB::table($table)->where('name', $name)->value('id');

        if ($existing !== null) {
            return $this->accounts[$name] = (int) $existing;
        }

        $now = now();

        return $this->accounts[$name] = (int) DB::table($table)->insertGetId([
            'name' => $name,
            'kind' => $kind,
            'currency' => Currencies::BASE,
            'is_active' => true,
            'sort_order' => count($this->accounts),
            'source' => 'migration',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** One line per account a movement touched. */
    private function createLines(): void
    {
        DbSchema::useSchema('finansije');

        Schema::create('stavke_transakcija', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_transaction_id')
                ->constrained('bankovne_transakcije')
                ->cascadeOnDelete();
            $table->foreignId('account_id')
                ->constrained('bankovni_racuni')
                ->restrictOnDelete();
            // Signed, exactly as the columns it replaces were: + in, − out.
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('amount_eur', 18, 2)->default(0);
            $table->auditColumns();
            $table->timestamps();
            // One line per account per movement — the three columns could hold
            // one amount each, and nothing is gained by letting a movement carry
            // two lines on the same account.
            $table->unique(['bank_transaction_id', 'account_id'], 'stavke_transakcija_movement_account_unique');
            $table->index('account_id', 'stavke_transakcija_account_id_index');
        });
    }

    /**
     * The movement's own net, and the signature the duplicate flag groups on.
     *
     * Both are maintained from the lines rather than summed on read, for the
     * same reason `amount_eur` has always been a column: the running balance is
     * a window function over the whole ledger and the duplicate flag is a
     * grouped aggregate, and neither can afford a join-and-group per page. They
     * are derived values kept in step by the model, not editable figures — no
     * balance is stored anywhere (rule 1).
     */
    private function addMovementTotals(): void
    {
        DbSchema::useSchema('finansije');

        Schema::table('bankovne_transakcije', function (Blueprint $table): void {
            $table->decimal('amount', 18, 2)->default(0)->after('description_2');
            $table->decimal('amount_eur', 18, 2)->default(0)->after('amount');
            $table->string('duplicate_signature')->nullable()->after('amount_eur');
            $table->index('duplicate_signature', 'bankovne_transakcije_signature_hash_index');
        });
    }

    /**
     * Move every non-zero amount onto its account's line.
     *
     * A zero was never a movement through that account — it was the absence of
     * one — so it produces no line, which is what keeps a one-account movement a
     * single row and a transfer two.
     */
    private function backfillLines(): void
    {
        DbSchema::useSchema('finansije');

        $now = now();

        DB::table('bankovne_transakcije')
            ->orderBy('id')
            ->chunkById(500, function ($movements) use ($now): void {
                $lines = [];
                $totals = [];

                foreach ($movements as $movement) {
                    $signature = [];
                    $amount = 0.0;
                    $amountEur = 0.0;

                    foreach (self::LEGACY as $column => $legacy) {
                        $value = round((float) $movement->{$column}, 2);

                        if ($value === 0.0) {
                            continue;
                        }

                        // Opened here if this is the first row that names it,
                        // so an install that never used Lovćen never gets it.
                        $accountId = $this->accountId($legacy['name'], $legacy['kind']);
                        $eur = round((float) $movement->{str_replace('_amount', '_amount_eur', $column)}, 2);

                        $lines[] = [
                            'bank_transaction_id' => $movement->id,
                            'account_id' => $accountId,
                            'amount' => $value,
                            'amount_eur' => $eur,
                            'source' => 'migration',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $signature[$accountId] = $accountId.':'.number_format($value, 2, '.', '');
                        $amount += $value;
                        $amountEur += $eur;
                    }

                    ksort($signature);

                    $totals[$movement->id] = [
                        'amount' => round($amount, 2),
                        'amount_eur' => round($amountEur, 2),
                        'duplicate_signature' => implode('|', [$movement->date, ...array_values($signature)]),
                    ];
                }

                if ($lines !== []) {
                    DB::table('stavke_transakcija')->insert($lines);
                }

                foreach ($totals as $id => $values) {
                    DB::table('bankovne_transakcije')->where('id', $id)->update($values);
                }
            });
    }

    private function dropLegacyColumns(): void
    {
        DbSchema::useSchema('finansije');

        Schema::table('bankovne_transakcije', function (Blueprint $table): void {
            // Named explicitly because it spans the columns being dropped.
            $table->dropIndex('bankovne_transakcije_signature_index');
            $table->dropColumn([
                'cash_amount', 'nlb_amount', 'lovcen_amount',
                'cash_amount_eur', 'nlb_amount_eur', 'lovcen_amount_eur',
            ]);
        });
    }

    /**
     * `method` becomes the account the settlement moved money through.
     *
     * `other` had no account behind it and becomes null, which is the same
     * claim: money was settled, but not through an account this app tracks, so
     * there is nothing to book.
     */
    private function settlementAccounts(): void
    {
        foreach (['placanja' => 'finansije', 'isplate_socijalne_pomoci' => 'putovanja'] as $table => $schema) {
            DbSchema::useSchema($schema);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('account_id')
                    ->nullable()
                    ->after('payment_date')
                    ->constrained('bankovni_racuni')
                    ->restrictOnDelete();
            });

            foreach (self::LEGACY as $legacy) {
                $named = DB::table($table)->where('method', $legacy['method'])->exists();

                if (! $named) {
                    continue;
                }

                DB::table($table)
                    ->where('method', $legacy['method'])
                    ->update(['account_id' => $this->accountId($legacy['name'], $legacy['kind'])]);
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('method');
            });
        }
    }

    public function down(): void
    {
        foreach (['placanja' => 'finansije', 'isplate_socijalne_pomoci' => 'putovanja'] as $table => $schema) {
            DbSchema::useSchema($schema);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('method')->nullable();
            });

            $accounts = DB::table(DbSchema::qualify('bankovni_racuni'))->pluck('name', 'id');

            foreach ($accounts as $id => $name) {
                $method = collect(self::LEGACY)->firstWhere('name', $name)['method'] ?? 'other';

                DB::table($table)->where('account_id', $id)->update(['method' => $method]);
            }

            DB::table($table)->whereNull('account_id')->update(['method' => 'other']);

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('account_id');
            });
        }

        DbSchema::useSchema('finansije');

        Schema::table('bankovne_transakcije', function (Blueprint $table): void {
            foreach (array_keys(self::LEGACY) as $column) {
                $table->decimal($column, 18, 2)->default(0);
                $table->decimal(str_replace('_amount', '_amount_eur', $column), 18, 2)->default(0);
            }
        });

        $accounts = DB::table(DbSchema::qualify('bankovni_racuni'))->pluck('name', 'id');

        foreach (DB::table('stavke_transakcija')->get() as $line) {
            $name = $accounts[$line->account_id] ?? null;
            $column = collect(self::LEGACY)->search(fn (array $legacy): bool => $legacy['name'] === $name);

            if ($column === false) {
                continue;
            }

            DB::table('bankovne_transakcije')->where('id', $line->bank_transaction_id)->update([
                $column => $line->amount,
                str_replace('_amount', '_amount_eur', $column) => $line->amount_eur,
            ]);
        }

        Schema::table('bankovne_transakcije', function (Blueprint $table): void {
            $table->index(
                ['date', 'cash_amount', 'nlb_amount', 'lovcen_amount'],
                'bankovne_transakcije_signature_index',
            );
            $table->dropIndex('bankovne_transakcije_signature_hash_index');
            $table->dropColumn(['amount', 'amount_eur', 'duplicate_signature']);
        });

        Schema::dropIfExists('stavke_transakcija');

        DbSchema::useSchema('sifarnici');
        Schema::dropIfExists('bankovni_racuni');
    }
};
