<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Polymorphic: a payment settles a payable_invoice, receivable_invoice, etc.
            $table->morphs('payable');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('EUR');
            $table->date('payment_date');
            $table->string('method'); // cash | nlb | lovcen | other
            // Link to a bank/cash movement once that module exists (no FK yet).
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->string('reference')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
