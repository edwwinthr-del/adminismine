<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payments.bank_transaction_id` was created before the bank module existed and
 * carried no constraint ("no FK yet"), which is what let a settled invoice and
 * its bank movement drift apart: deleting the movement left the payment pointing
 * at an id that no longer existed. Making it a real foreign key is what turns the
 * match into a tracked reference instead of a remembered number.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ids left behind by movements deleted before the constraint existed —
        // they would refuse the new key, and they are exactly the orphans it
        // is being added to prevent.
        DB::table('payments')
            ->whereNotNull('bank_transaction_id')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('bank_transactions')
                ->whereColumn('bank_transactions.id', 'payments.bank_transaction_id'))
            ->update(['bank_transaction_id' => null]);

        // The column is already indexed by add_performance_indexes, so the key
        // only has to add the constraint itself.
        //
        // SQLite cannot add a constraint to an existing table; the tests build
        // the schema from scratch on it, so the guarantee that matters lives on
        // Postgres and the behaviour is enforced in InvoiceSettlementService
        // either way.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('bank_transaction_id')
                ->references('id')
                ->on('bank_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['bank_transaction_id']);
        });
    }
};
