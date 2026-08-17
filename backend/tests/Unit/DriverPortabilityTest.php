<?php

namespace Tests\Unit;

use App\Support\MonthPeriod;
use App\Support\SearchTerm;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two places the suite cannot otherwise reach.
 *
 * `SearchTerm` and `MonthPeriod::sqlMonth()` exist *because* SQLite and
 * Postgres disagree — LIKE is case-sensitive on one and not the other, and the
 * two truncate dates differently. The suite runs on in-memory SQLite while
 * production runs on Postgres, so until now every test exercised the SQLite
 * branch and the Postgres branch shipped unexecuted: a typo in the ILIKE arm
 * would have been invisible behind a green suite, and it would have surfaced as
 * "search finds nothing in production" with every test still passing.
 *
 * Neither test needs a Postgres server. `sqlMonth()` is a pure function of the
 * driver name, and `toSql()` compiles a query without ever touching PDO — so
 * the Postgres grammar can be asked what it *would* emit.
 */
class DriverPortabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never connected to; only its grammar and driver name are read.
        config(['database.connections.pgsql_probe' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'unused',
            'username' => 'unused',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]]);
    }

    public function test_month_truncation_is_written_for_each_driver(): void
    {
        $this->assertSame("strftime('%Y-%m', date)", MonthPeriod::sqlMonth('sqlite', 'date'));
        $this->assertSame("to_char(date, 'YYYY-MM')", MonthPeriod::sqlMonth('pgsql', 'date'));
        // Anything else falls back to the MySQL spelling rather than breaking.
        $this->assertSame("DATE_FORMAT(date, '%Y-%m')", MonthPeriod::sqlMonth('mysql', 'date'));
    }

    /** Postgres gets ILIKE; a plain LIKE there would be case-sensitive. */
    public function test_search_uses_a_case_insensitive_operator_on_postgres(): void
    {
        $query = DB::connection('pgsql_probe')->table('dobavljaci');
        SearchTerm::apply($query, 'name', 'acme');

        $sql = $query->toSql();

        $this->assertStringContainsString('ilike', strtolower($sql));
        $this->assertStringContainsString("escape '\\'", strtolower($sql));
        // The column is qualified with its table, so a later join cannot make it ambiguous.
        $this->assertStringContainsString('"dobavljaci"."name"', $sql);
        // Postgres folds case itself, so the term is not pre-lowered.
        $this->assertSame(['%acme%'], $query->getBindings());
    }

    /** SQLite folds ASCII only, so the column and the term are lowered in PHP. */
    public function test_search_lowers_both_sides_on_sqlite(): void
    {
        $query = DB::connection('sqlite')->table('dobavljaci');
        SearchTerm::apply($query, 'name', 'ČAČAK');

        $sql = strtolower($query->toSql());

        $this->assertStringContainsString('lower(', $sql);
        $this->assertStringContainsString("escape '\\'", $sql);
        // mb_strtolower, not strtolower: Č is not ASCII.
        $this->assertSame(['%čačak%'], $query->getBindings());
    }

    /**
     * The divergence the suite cannot paper over, pinned so it is known rather
     * than discovered.
     *
     * SQLite's `lower()` folds ASCII and nothing else: `lower('ČAČAK')` is
     * `'ČaČak'`, so `LOWER(col) LIKE '%čačak%'` is false. Postgres folds the
     * whole string, and `'ČAČAK Trans' ILIKE '%čačak%'` is true. Both measured,
     * not assumed — the Postgres half against the real 5433 database.
     *
     * Production is the correct one, so nothing here should be "fixed" by
     * contorting SearchTerm to satisfy the test driver. What matters is that
     * **a feature test written against Serbian or Turkish text will fail under
     * SQLite while the same search works in production**, and that is a
     * property of the test environment, not of the app.
     */
    public function test_case_folding_of_non_ascii_differs_by_driver(): void
    {
        $folded = DB::connection('sqlite')
            ->selectOne("select lower('ČAČAK') as folded")->folded;

        // If this ever starts returning 'čačak', SQLite gained ICU folding and
        // the caveat above — and the ASCII-only feature tests — can be dropped.
        $this->assertSame('ČaČak', $folded, 'SQLite unexpectedly folded non-ASCII');
        $this->assertNotSame('čačak', $folded);
    }

    /**
     * A `%` typed by a user is a literal, not a wildcard — without escaping it
     * turns a search into a full scan that matches everything.
     */
    public function test_wildcards_typed_by_a_user_are_escaped_on_both_drivers(): void
    {
        foreach (['sqlite', 'pgsql_probe'] as $connection) {
            $query = DB::connection($connection)->table('dobavljaci');
            SearchTerm::apply($query, 'name', '50%_off');

            $binding = $query->getBindings()[0];

            $this->assertStringContainsString('\%', $binding, "{$connection}: % not escaped");
            $this->assertStringContainsString('\_', $binding, "{$connection}: _ not escaped");
        }
    }
}
