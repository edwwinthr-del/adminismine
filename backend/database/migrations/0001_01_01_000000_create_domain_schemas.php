<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every schema the application owns, created before anything is put in one.
 *
 * Nothing here relies on a schema having been created by hand: an empty
 * Postgres database reaches the finished structure by running the migrations
 * and nothing else. On SQLite this is a no-op — that driver keeps every table
 * in a single namespace, which is why table names are unique across the whole
 * database rather than merely within a schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::createSchemas(DB::connection());

        $this->moveMigrationLedgerIntoSistem();
    }

    /**
     * The migrator creates its own ledger table before it runs anything, so on a
     * fresh database `migracije` is already there — and, with no schema existing
     * yet, Postgres put it in `public`. Move it now, so that "every table lives
     * in a domain schema" holds for the one table the migrations do not create.
     */
    private function moveMigrationLedgerIntoSistem(): void
    {
        if (! DbSchema::supportsSchemas(DB::connection())) {
            return;
        }

        $table = config('database.migrations.table', 'migracije');

        $misplaced = DB::selectOne(
            'select table_schema from information_schema.tables
             where table_name = ? and table_schema <> ?',
            [$table, 'sistem']
        );

        if ($misplaced === null) {
            return;
        }

        DB::statement("ALTER TABLE {$misplaced->table_schema}.{$table} SET SCHEMA sistem");
    }

    public function down(): void
    {
        if (! DbSchema::supportsSchemas(DB::connection())) {
            return;
        }

        // RESTRICT, not CASCADE: dropping a schema that still holds tables is a
        // mistake worth failing on rather than a licence to delete them.
        foreach (array_reverse(DbSchema::SCHEMAS) as $schema) {
            DB::statement('DROP SCHEMA IF EXISTS '.$schema.' RESTRICT');
        }
    }
};
