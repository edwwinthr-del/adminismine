<?php

namespace App\Console\Commands;

use App\Support\DbSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Takes a database created by the pre-restructure migrations to the current
 * shape, in place and without moving a single row.
 *
 * An empty database does not need this: `php artisan migrate` reaches the final
 * structure on its own. This exists for the databases that already hold data —
 * every table is renamed and moved with ALTER, which Postgres does as a catalog
 * update, so a 100,000 EUR receivable is the same row before and after and no
 * dump/restore step can lose it.
 *
 * Run once. It refuses to run twice, and refuses to run at all if the legacy
 * tables are not the ones actually present.
 */
class RestructureDatabase extends Command
{
    protected $signature = 'db:restructure
                            {--dry-run : Print the statements without executing them}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Move and rename the legacy public-schema tables into the domain schemas, preserving all data';

    /**
     * Index and constraint names the new migrations create with these exact
     * literals, so they must survive their table's rename untouched. All of them
     * come from Spatie's permission migration, which names them by hand.
     *
     * @var list<string>
     */
    private const KEEP_NAMES = [
        'model_has_permissions_model_id_model_type_index',
        'model_has_roles_model_id_model_type_index',
        'model_has_permissions_permission_model_type_primary',
        'model_has_roles_role_model_type_primary',
        'role_has_permissions_permission_id_role_id_primary',
        'roles_team_foreign_key_index',
        'model_has_permissions_team_foreign_key_index',
        'model_has_roles_team_foreign_key_index',
    ];

    /**
     * Names whose mechanical prefix swap would not match what the migrations
     * actually create. The exchange-rate unique key is the only one: prefixing
     * it with the new table name would run past Postgres' 63-character limit,
     * so the migration names it explicitly and so does this.
     *
     * @var array<string, string>
     */
    private const RENAME_OVERRIDES = [
        'exchange_rates_base_currency_quote_currency_rate_date_unique' => 'kursevi_valuta_par_datum_unique',
    ];

    public function handle(): int
    {
        $connection = DB::connection();

        if (! DbSchema::supportsSchemas($connection)) {
            $this->error('This command only applies to Postgres; '.$connection->getDriverName().' has no schemas.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->alreadyRestructured()) {
            $this->info('Already restructured — sistem.migracije exists. Nothing to do.');

            return self::SUCCESS;
        }

        $legacy = $this->legacyTablesPresent();

        if ($legacy === []) {
            $this->error('No legacy tables found in `public`. An empty database should just run `php artisan migrate`.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Found %d legacy tables in `public` holding %s rows.',
            count($legacy),
            number_format($this->countLegacyRows($legacy)),
        ));

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Move and rename them now?', true)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $statements = $this->buildStatements($legacy);

        if ($dryRun) {
            foreach ($statements as $sql) {
                $this->line($sql.';');
            }
            $this->info(count($statements).' statements (dry run — nothing executed).');

            return self::SUCCESS;
        }

        $before = $this->countLegacyRows($legacy);

        // One transaction: DDL is transactional on Postgres, so a failure
        // halfway leaves the database exactly as it was rather than half moved.
        $connection->transaction(function () use ($connection, $statements): void {
            foreach ($statements as $sql) {
                $connection->statement($sql);
            }
        });

        $this->rewriteMigrationLedger();

        $after = $this->countMovedRows($legacy);

        $this->newLine();
        $this->info("Done. {$before} rows before, {$after} rows after.");

        if ($before !== $after) {
            $this->error('Row count changed — investigate before using this database.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function alreadyRestructured(): bool
    {
        return (bool) DB::selectOne(
            "select 1 as found from information_schema.tables
             where table_schema = 'sistem' and table_name = 'migracije'"
        );
    }

    /** @return array<string, string> legacy name => new name, for tables actually present */
    private function legacyTablesPresent(): array
    {
        $present = collect(DB::select(
            "select table_name from information_schema.tables
             where table_schema = 'public' and table_type = 'BASE TABLE'"
        ))->pluck('table_name')->all();

        return array_filter(
            DbSchema::RENAMES,
            static fn (string $new, string $old): bool => in_array($old, $present, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Rows still sitting in `public` under their legacy names.
     *
     * The migration ledger is left out of both counts: this command rewrites it
     * on purpose, so including it would make the before/after comparison report
     * a difference on every successful run and hide a real one.
     *
     * @param  array<string, string>  $legacy  legacy name => new name
     */
    private function countLegacyRows(array $legacy): int
    {
        $total = 0;

        foreach (array_keys($legacy) as $old) {
            if ($old === 'migrations') {
                continue;
            }

            $total += (int) DB::selectOne("select count(*) as c from public.{$old}")->c;
        }

        return $total;
    }

    /**
     * The same rows after the move, counted where they now live. Compared
     * against the figure taken before, this is the check that the transition
     * moved everything and dropped nothing.
     *
     * @param  array<string, string>  $legacy  legacy name => new name
     */
    private function countMovedRows(array $legacy): int
    {
        $total = 0;

        foreach ($legacy as $old => $new) {
            if ($old === 'migrations') {
                continue;
            }

            $schema = DbSchema::schemaFor($new);
            $total += (int) DB::selectOne("select count(*) as c from {$schema}.{$new}")->c;
        }

        return $total;
    }

    /**
     * @param  array<string, string>  $legacy
     * @return list<string>
     */
    private function buildStatements(array $legacy): array
    {
        $statements = [];

        foreach (DbSchema::SCHEMAS as $schema) {
            $statements[] = 'CREATE SCHEMA IF NOT EXISTS '.$schema;
        }

        foreach ($legacy as $old => $new) {
            $target = DbSchema::schemaFor($new);

            // Move first, rename second: the target schemas are empty, so this
            // order cannot collide with a table that happens to share a name.
            $statements[] = "ALTER TABLE public.{$old} SET SCHEMA {$target}";
            $statements[] = "ALTER TABLE {$target}.{$old} RENAME TO {$new}";

            foreach ($this->relationsToRename($old, $new) as $sql) {
                $statements[] = $sql;
            }
        }

        return $statements;
    }

    /**
     * Rename the indexes, constraints and sequences that carry the old table's
     * name, so a database transitioned from legacy and one built by running the
     * migrations on an empty database end up with the same identifiers.
     *
     * @return list<string>
     */
    private function relationsToRename(string $old, string $new): array
    {
        $target = DbSchema::schemaFor($new);
        $statements = [];

        // Introspection happens before anything has moved, so the catalog is
        // read at the table's current location (`public`, legacy name) while the
        // statements are written for where it will be by the time they run —
        // SET SCHEMA carries a table's indexes and owned sequences with it.
        //
        // Selected by ownership rather than by name prefix: `cache` and
        // `cache_locks` share a prefix, so a LIKE would have handed
        // cache_locks' indexes to cache and renamed them twice.
        $relations = DB::select(
            "select ic.relname as name, 'i' as kind
             from pg_index i
             join pg_class ic on ic.oid = i.indexrelid
             join pg_class tc on tc.oid = i.indrelid
             join pg_namespace n on n.oid = tc.relnamespace
             where n.nspname = 'public' and tc.relname = ?
             union
             select s.relname as name, 'S' as kind
             from pg_class s
             join pg_depend d on d.objid = s.oid and d.classid = 'pg_class'::regclass
             join pg_class t on t.oid = d.refobjid
             join pg_namespace n on n.oid = t.relnamespace
             where s.relkind = 'S' and n.nspname = 'public' and t.relname = ?",
            [$old, $old]
        );

        foreach ($relations as $relation) {
            $renamed = $this->rename($relation->name, $old, $new);

            if ($renamed === null) {
                continue;
            }

            $keyword = $relation->kind === 'S' ? 'SEQUENCE' : 'INDEX';
            $statements[] = "ALTER {$keyword} {$target}.{$relation->name} RENAME TO {$renamed}";
        }

        // Constraints that are not backed by an index of the same name — chiefly
        // the foreign keys. A unique/primary constraint shares its name with its
        // index, and renaming the index renames the constraint with it.
        $constraints = DB::select(
            'select con.conname as name
             from pg_constraint con
             join pg_class c on c.oid = con.conrelid
             join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = \'public\' and c.relname = ? and con.contype = \'f\'
               and con.conname like ?',
            [$old, $old.'\_%']
        );

        foreach ($constraints as $constraint) {
            $renamed = $this->rename($constraint->name, $old, $new);

            if ($renamed === null) {
                continue;
            }

            $statements[] = "ALTER TABLE {$target}.{$new} RENAME CONSTRAINT {$constraint->name} TO {$renamed}";
        }

        return $statements;
    }

    /** The new name for one index/constraint/sequence, or null to leave it alone. */
    private function rename(string $name, string $old, string $new): ?string
    {
        if (in_array($name, self::KEEP_NAMES, true)) {
            return null;
        }

        if (isset(self::RENAME_OVERRIDES[$name])) {
            return self::RENAME_OVERRIDES[$name];
        }

        // Anything not named after its table keeps the name it has — the
        // activity log's `subject` and `causer` indexes are named that way in
        // Spatie's migration and in ours alike.
        if (! str_starts_with($name, $old.'_')) {
            return null;
        }

        $renamed = $new.substr($name, strlen($old));

        if (strlen($renamed) > 63) {
            $this->warn("Skipped {$name}: the renamed identifier would exceed Postgres' 63-character limit.");

            return null;
        }

        return $renamed === $name ? null : $renamed;
    }

    /**
     * Replace the old migration filenames with the current ones, all in batch 1.
     *
     * The structure they describe is already in place, so they must be recorded
     * as run — otherwise the next `migrate` would try to create tables that
     * exist. Read from disk rather than hardcoded, so adding a migration before
     * this command is run cannot desynchronise the two.
     */
    private function rewriteMigrationLedger(): void
    {
        $names = collect(File::files(database_path('migrations')))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->map(fn ($file): string => $file->getBasename('.php'))
            ->sort()
            ->values();

        DB::table('migracije')->delete();

        DB::table('migracije')->insert(
            $names->map(fn (string $name): array => ['migration' => $name, 'batch' => 1])->all()
        );

        $this->line("Migration ledger rewritten: {$names->count()} migrations recorded as run.");
    }
}
