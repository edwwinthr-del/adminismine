<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // North-Ex style loans and advances. Repayments are ordinary polymorphic
        // `payments` rows, so a repayment can be matched to a bank/cash movement
        // exactly like an invoice settlement.
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('counterparty');
            // Whose money it is: `received` = the company borrowed and owes it
            // back, `given` = the company lent it out and is owed.
            $table->string('direction')->default('received'); // received | given
            $table->string('reference_number')->nullable();
            $table->date('loan_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('original_amount', 18, 2);
            $table->decimal('exchange_rate', 18, 10)->nullable(); // 1 EUR = rate <currency>
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2);
            // Cached, recomputed from the linked repayments.
            $table->decimal('repaid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('outstanding'); // outstanding | partial | repaid
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->auditColumns();
            $table->timestamps();

            $table->index('counterparty');
            $table->index('loan_date');
            $table->index('status');
            $table->index('direction');
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
