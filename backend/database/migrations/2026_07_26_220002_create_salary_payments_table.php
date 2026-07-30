<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // Always the first day of the salary month.
            $table->date('salary_month');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('base_salary', 18, 2);
            $table->decimal('adjustments', 18, 2)->default(0); // bonuses (+) / corrections (-)
            $table->decimal('deductions', 18, 2)->default(0);
            // Cached, recomputed from the fields above and the linked payments.
            $table->decimal('net_salary_due', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            $table->string('attachment_path')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->unique(['employee_id', 'salary_month']);
            $table->index('salary_month');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
    }
};
