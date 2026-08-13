<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money core learns what a foreign currency is.
 *
 * `payable_invoices`, `receivable_invoices`, `payments` and `bank_transactions`
 * each carried a `currency` column and nothing else — no rate, no rate date, no
 * EUR figure — while every balance, every invoice total and every dashboard
 * number summed the raw amounts across currencies. A 100,000 TRY payable
 * (~2,600 EUR) settled as a 100,000 EUR outflow, and nothing anywhere warned.
 * The API accepted `currency` on create and the import templates offered
 * EUR/TRY/USD, so this was an invited path rather than a theoretical one.
 *
 * Rule 5's shape is now on all four: the original amount is never rewritten, and
 * the rate that produced the EUR figure is stored beside it.
 *
 * Backfill is deliberately 1:1. Until now the app read every amount as EUR, so
 * that is what every reported figure has meant; writing `amount_eur = amount`
 * reproduces today's numbers exactly rather than silently restating history.
 * `exchange_rate` stays null, which is the converter's own marker for "this was
 * already the accounting currency" — so any pre-existing row in another currency
 * is findable afterwards with `currency <> 'EUR' and exchange_rate is null`.
 */
return new class extends Migration
{
    /** table => the amount columns that need an EUR twin. */
    private const TABLES = [
        'payable_invoices' => ['original_amount' => 'amount_eur'],
        'receivable_invoices' => ['invoice_amount' => 'amount_eur'],
        'payments' => ['amount' => 'amount_eur'],
        'bank_transactions' => [
            'cash_amount' => 'cash_amount_eur',
            'nlb_amount' => 'nlb_amount_eur',
            'lovcen_amount' => 'lovcen_amount_eur',
        ],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                // Same precision as ExchangeRate::rate, so a stored rate can be
                // written back without losing digits.
                $blueprint->decimal('exchange_rate', 20, 10)->nullable()->after('currency');
                $blueprint->date('exchange_rate_date')->nullable()->after('exchange_rate');

                foreach ($columns as $eurColumn) {
                    $blueprint->decimal($eurColumn, 18, 2)->default(0)->after('exchange_rate_date');
                }
            });

            foreach ($columns as $source => $eurColumn) {
                DB::table($table)->update([$eurColumn => DB::raw(
                    'COALESCE('.$this->quote($source).', 0)'
                )]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropColumn(array_merge(
                    ['exchange_rate', 'exchange_rate_date'],
                    array_values($columns),
                ));
            });
        }
    }

    /** Quote an identifier for the connection in use (both drivers accept "x"). */
    private function quote(string $column): string
    {
        return '"'.str_replace('"', '""', $column).'"';
    }
};
