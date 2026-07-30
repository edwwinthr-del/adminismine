<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('worksite_id')->constrained('worksites')->restrictOnDelete();
            // Who submitted from the field (null when the office enters it directly).
            $table->foreignId('master_id')->nullable()->constrained('masters')->nullOnDelete();

            // present | absent | holiday | sick_leave | unpaid_leave | other
            $table->string('status')->default('present');
            $table->decimal('regular_hours', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->string('overtime_reason')->nullable();
            $table->string('note')->nullable();

            // draft | submitted | approved | rejected. Attendance must be approved
            // before it may feed salary calculations.
            $table->string('approval_status')->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            // Daily earned pay: computed from the employee's salary settings and the
            // hours above. Kept on the row so the figures stay visible and auditable.
            $table->string('currency', 3)->default('EUR');
            $table->decimal('daily_rate', 18, 2)->nullable();
            $table->unsignedSmallInteger('working_days_basis')->nullable();
            $table->decimal('regular_amount', 18, 2)->default(0);
            $table->decimal('overtime_amount', 18, 2)->default(0);
            $table->decimal('adjustment_amount', 18, 2)->default(0);
            $table->string('adjustment_reason')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->boolean('approved_for_payroll')->default(false);

            $table->auditColumns();
            $table->timestamps();

            // One attendance record per employee per day, so a day can never be paid twice.
            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'worksite_id']);
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
