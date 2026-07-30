<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_invoice_id')->constrained('receivable_invoices')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('deduction_date');
            $table->string('reason')->nullable();
            $table->auditColumns();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_deductions');
    }
};
