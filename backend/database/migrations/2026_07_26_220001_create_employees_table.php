<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('origin_country')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('id_number')->nullable();
            $table->string('job_role')->nullable();

            // Bank details. `bank_account_status` is canonical, never a translated label
            // (the ISCILER ICIN BANKA HESAPLARI sheet uses Turkish/Serbian wording).
            $table->string('bank_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_status')->default('unknown'); // unknown | none | pending | open

            // Salary settings. Daily earned pay derives from these (see Masters module).
            $table->decimal('base_salary', 18, 2)->nullable();
            $table->string('salary_currency', 3)->default('EUR');
            $table->string('salary_period')->default('monthly');          // monthly | daily
            $table->string('salary_calculation_rule')->default('working_days'); // working_days | fixed_daily
            $table->decimal('daily_rate_override', 18, 2)->nullable();
            $table->decimal('overtime_multiplier', 5, 2)->nullable();
            $table->decimal('overtime_hourly_rate', 18, 2)->nullable();

            // Contract & document expiry tracking.
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->date('work_permit_expiry')->nullable();
            $table->date('residence_permit_expiry')->nullable();
            $table->date('medical_exam_expiry')->nullable();
            $table->date('safety_training_expiry')->nullable();

            $table->string('status')->default('active'); // active | inactive
            $table->auditColumns();
            $table->softDeletes(); // removal keeps the historical record
            $table->timestamps();

            $table->index('status');
            $table->index('last_name');
            $table->index('bank_account_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
