<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of where every table lives.
 *
 * Postgres holds the tables in domain schemas; SQLite (the test connection)
 * has no schemas at all, so on that driver every name resolves bare. Because
 * table names are unique across the whole database, the same unqualified name
 * works on both drivers as long as the pgsql connection's `search_path` lists
 * every schema — which is what `searchPath()` produces for config/database.php.
 *
 * Migrations do not qualify their table names either. They call
 * `DbSchema::useSchema()` first, which puts the target schema at the head of
 * the session search_path: new tables land there, and every other schema stays
 * visible so cross-schema foreign keys still resolve by bare name. Keeping the
 * names bare is what makes index and constraint names identical on Postgres and
 * SQLite instead of picking up a schema prefix on one driver only.
 */
final class DbSchema
{
    /**
     * Every schema the application owns.
     *
     * Order matters only for readability; `public` is appended separately and
     * is kept solely so extensions installed there stay reachable.
     *
     * @var list<string>
     */
    public const SCHEMAS = [
        'sistem',
        'bezbednost',
        'sifarnici',
        'finansije',
        'kadrovi',
        'proizvodnja',
        'smestaj',
        'putovanja',
        'carina',
        'dokumenti',
        'obavestenja',
        'asistent',
    ];

    /**
     * table => schema, for every table in the database.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        // sistem — framework plumbing, no business meaning.
        'migracije' => 'sistem',
        'kes' => 'sistem',
        'kes_zakljucavanja' => 'sistem',
        'poslovi' => 'sistem',
        'grupe_poslova' => 'sistem',
        'neuspeli_poslovi' => 'sistem',
        'sesije' => 'sistem',
        'tokeni_za_reset_lozinke' => 'sistem',
        'pristupni_tokeni' => 'sistem',

        // bezbednost — identity, authorisation and the audit trail.
        'korisnici' => 'bezbednost',
        'uloge' => 'bezbednost',
        'dozvole' => 'bezbednost',
        'uloge_modela' => 'bezbednost',
        'dozvole_modela' => 'bezbednost',
        'dozvole_uloga' => 'bezbednost',
        'dnevnik_aktivnosti' => 'bezbednost',

        // sifarnici — company-wide reference data.
        'podesavanja_kompanije' => 'sifarnici',
        'kursevi_valuta' => 'sifarnici',
        'dobavljaci' => 'sifarnici',
        'klijenti' => 'sifarnici',
        'bankovni_racuni' => 'sifarnici',
        'prevodi_pojmova' => 'sifarnici',
        'recnici' => 'sifarnici',

        // finansije — money.
        'ulazne_fakture' => 'finansije',
        'izlazne_fakture' => 'finansije',
        'odbici_izlaznih_faktura' => 'finansije',
        'placanja' => 'finansije',
        'bankovne_transakcije' => 'finansije',
        'stavke_transakcija' => 'finansije',
        'pozajmice' => 'finansije',

        // kadrovi — people and payroll.
        'radnici' => 'kadrovi',
        'isplate_zarada' => 'kadrovi',
        'majstori' => 'kadrovi',
        'radnik_gradiliste' => 'kadrovi',
        'majstor_gradiliste' => 'kadrovi',
        'evidencija_prisustva' => 'kadrovi',
        'podesavanja_radnih_dana' => 'kadrovi',
        'potrebe_radnika' => 'kadrovi',

        // proizvodnja — the work structure and what comes out of it.
        'rudnici' => 'proizvodnja',
        'projekti' => 'proizvodnja',
        'gradilista' => 'proizvodnja',
        'evidencija_proizvodnje' => 'proizvodnja',
        'masine' => 'proizvodnja',

        // smestaj — worker housing.
        'kuce' => 'smestaj',
        'useljenja' => 'smestaj',
        'placanja_kirije' => 'smestaj',
        'rezijski_racuni' => 'smestaj',
        'odbici_za_smestaj' => 'smestaj',

        // putovanja — travel, tickets and social assistance.
        'avionske_karte' => 'putovanja',
        'putni_troskovi' => 'putovanja',
        'isplate_socijalne_pomoci' => 'putovanja',

        // carina — customs and transport paperwork.
        'carinski_dokumenti' => 'carina',

        // dokumenti — stored files and spreadsheet intake.
        'prilozi' => 'dokumenti',
        'uvozne_serije' => 'dokumenti',
        'uvozni_redovi' => 'dokumenti',

        // obavestenja — generated notifications and their rules.
        'obavestenja' => 'obavestenja',
        'pravila_obavestenja' => 'obavestenja',
        'korisnicka_podesavanja' => 'obavestenja',

        // asistent — the LLM assistant.
        'ai_predlozi' => 'asistent',
        'poruke_asistenta' => 'asistent',
    ];

    /**
     * The pre-restructure English name of every table, for the one-off
     * transition of an existing database. Nothing in the running application
     * reads this — only `db:restructure` does.
     *
     * @var array<string, string> legacy name => current name
     */
    public const RENAMES = [
        'migrations' => 'migracije',
        'cache' => 'kes',
        'cache_locks' => 'kes_zakljucavanja',
        'jobs' => 'poslovi',
        'job_batches' => 'grupe_poslova',
        'failed_jobs' => 'neuspeli_poslovi',
        'sessions' => 'sesije',
        'password_reset_tokens' => 'tokeni_za_reset_lozinke',
        'personal_access_tokens' => 'pristupni_tokeni',

        'users' => 'korisnici',
        'roles' => 'uloge',
        'permissions' => 'dozvole',
        'model_has_roles' => 'uloge_modela',
        'model_has_permissions' => 'dozvole_modela',
        'role_has_permissions' => 'dozvole_uloga',
        'activity_log' => 'dnevnik_aktivnosti',

        'company_settings' => 'podesavanja_kompanije',
        'exchange_rates' => 'kursevi_valuta',
        'suppliers' => 'dobavljaci',
        'clients' => 'klijenti',

        'payable_invoices' => 'ulazne_fakture',
        'receivable_invoices' => 'izlazne_fakture',
        'receivable_deductions' => 'odbici_izlaznih_faktura',
        'payments' => 'placanja',
        'bank_transactions' => 'bankovne_transakcije',
        'loans' => 'pozajmice',

        'employees' => 'radnici',
        'salary_payments' => 'isplate_zarada',
        'masters' => 'majstori',
        'employee_worksite' => 'radnik_gradiliste',
        'master_worksite' => 'majstor_gradiliste',
        'attendance_records' => 'evidencija_prisustva',
        'working_day_settings' => 'podesavanja_radnih_dana',
        'worker_needs' => 'potrebe_radnika',

        'mines' => 'rudnici',
        'projects' => 'projekti',
        'worksites' => 'gradilista',
        'production_records' => 'evidencija_proizvodnje',
        'machines' => 'masine',

        'houses' => 'kuce',
        'house_occupancies' => 'useljenja',
        'rent_payments' => 'placanja_kirije',
        'utility_bills' => 'rezijski_racuni',
        'housing_deductions' => 'odbici_za_smestaj',

        'flight_tickets' => 'avionske_karte',
        'travel_expenses' => 'putni_troskovi',
        'social_assistance_payments' => 'isplate_socijalne_pomoci',

        'customs_documents' => 'carinski_dokumenti',

        'file_attachments' => 'prilozi',
        'import_batches' => 'uvozne_serije',
        'import_rows' => 'uvozni_redovi',

        'notifications' => 'obavestenja',
        'notification_rules' => 'pravila_obavestenja',
        'user_notification_preferences' => 'korisnicka_podesavanja',

        'ai_suggestions' => 'ai_predlozi',
        'assistant_messages' => 'poruke_asistenta',
    ];

    /**
     * The `search_path` for config/database.php: every domain schema, then
     * `public` last.
     */
    public static function searchPath(): string
    {
        return implode(',', [...self::SCHEMAS, 'public']);
    }

    /** Schemas are a Postgres feature; SQLite keeps everything in one namespace. */
    public static function supportsSchemas(?Connection $connection = null): bool
    {
        return ($connection ?? DB::connection())->getDriverName() === 'pgsql';
    }

    public static function schemaFor(string $table): string
    {
        return self::TABLES[$table]
            ?? throw new \InvalidArgumentException("Unknown table [{$table}] — add it to DbSchema::TABLES.");
    }

    /**
     * `schema.table` on Postgres, bare `table` on SQLite. Only needed where a
     * name has to be spelled out in SQL rather than resolved via search_path.
     */
    public static function qualify(string $table, ?Connection $connection = null): string
    {
        return self::supportsSchemas($connection)
            ? self::schemaFor($table).'.'.$table
            : $table;
    }

    /**
     * Aim the session at one schema for the duration of a migration: the target
     * schema first (so `Schema::create()` puts the table there), every other
     * schema behind it (so foreign keys to other domains still resolve).
     */
    public static function useSchema(string $schema, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if (! self::supportsSchemas($connection)) {
            return;
        }

        $path = [$schema, ...array_diff(self::SCHEMAS, [$schema]), 'public'];

        $connection->statement('SET search_path TO '.implode(', ', $path));
    }

    /** Every schema, created if missing. Safe to call repeatedly. */
    public static function createSchemas(?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if (! self::supportsSchemas($connection)) {
            return;
        }

        foreach (self::SCHEMAS as $schema) {
            $connection->statement('CREATE SCHEMA IF NOT EXISTS '.$schema);
        }
    }

    /** @return list<string> tables belonging to $schema */
    public static function tablesIn(string $schema): array
    {
        return array_keys(array_filter(
            self::TABLES,
            static fn (string $owner): bool => $owner === $schema,
        ));
    }
}
