<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description_1')->nullable();
            $table->string('description_2')->nullable();
            // Signed per-account amounts (+ in / - out). A transfer moves
            // between two accounts within one row.
            $table->decimal('cash_amount', 18, 2)->default(0);
            $table->decimal('nlb_amount', 18, 2)->default(0);
            $table->decimal('lovcen_amount', 18, 2)->default(0);
            $table->string('category')->nullable(); // income|expense|transfer|loan|payroll|housing|travel|other
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('currency', 3)->default('EUR');
            $table->string('import_source')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index('date');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
