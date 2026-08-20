<?php

use App\Support\Currencies;
use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The EUR twin every money table already carried, for the five that did not.
 *
 * Rent, utility bills, salaries, housing charges and receivable deductions all
 * stored `currency` and an amount and nothing else, so their cross-record totals
 * — the dashboard's housing tile, the housing summary, the salary month totals —
 * added a TRY face value straight onto a EUR one. A EUR-correct tile reading
 * 3,900 instead of 500 is not a rounding problem; it is the same class of bug
 * that made a 2,600 EUR bill read as 100,000 before the finance tables were
 * given these columns (rule 5).
 *
 * The original amount is never rewritten: `amount_eur` is derived alongside it,
 * with the rate that produced it stored on the row.
 */
return new class extends Migration
{
    /**
     * Each table with the column holding the amount in its own currency, and the
     * date the rate should be read as of.
     *
     * @var array<string, array{schema: string, amount: string, date: string}>
     */
    private const TABLES = [
        'placanja_kirije' => ['schema' => 'smestaj', 'amount' => 'rent_amount_due', 'date' => 'month'],
        'rezijski_racuni' => ['schema' => 'smestaj', 'amount' => 'amount', 'date' => 'billing_period'],
        'odbici_za_smestaj' => ['schema' => 'smestaj', 'amount' => null, 'date' => 'month'],
        'isplate_zarada' => ['schema' => 'kadrovi', 'amount' => 'net_salary_due', 'date' => 'salary_month'],
        'odbici_izlaznih_faktura' => ['schema' => 'finansije', 'amount' => 'amount', 'date' => 'deduction_date'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $definition) {
            DbSchema::useSchema($definition['schema']);

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                // Deductions were the one settlement with no currency at all —
                // its amount was simply assumed to be EUR, which is only true
                // until someone offsets a TRY invoice.
                if (! Schema::hasColumn($table, 'currency')) {
                    $blueprint->string('currency', 3)->default(Currencies::BASE);
                }

                $blueprint->decimal('exchange_rate', 20, 10)->nullable();
                $blueprint->date('exchange_rate_date')->nullable();
                $blueprint->decimal('amount_eur', 18, 2)->default(0);
            });
        }

        $this->backfill();
    }

    /**
     * Price what is already stored.
     *
     * Rows in the accounting currency convert 1:1 and are done in one statement.
     * Anything else is left at its face value with no rate recorded: the app
     * cannot invent the rate that applied on a date it has no quote for, and a
     * silent 1:1 is exactly the assumption these columns exist to stop. Such a
     * row prices itself correctly the next time it is saved, once a rate for its
     * date exists.
     */
    private function backfill(): void
    {
        foreach (self::TABLES as $table => $definition) {
            DbSchema::useSchema($definition['schema']);

            $amount = $definition['amount'];

            DB::table($table)
                ->where('currency', Currencies::BASE)
                ->update([
                    'amount_eur' => $amount === null
                        // Housing charges are a rent share plus a utility share;
                        // what they cost the worker is the two together.
                        ? DB::raw('COALESCE(rent_share, 0) + COALESCE(utility_share, 0)')
                        : DB::raw("COALESCE({$amount}, 0)"),
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $definition) {
            DbSchema::useSchema($definition['schema']);

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropColumn(['exchange_rate', 'exchange_rate_date', 'amount_eur']);

                if ($table === 'odbici_izlaznih_faktura') {
                    $blueprint->dropColumn('currency');
                }
            });
        }
    }
};
