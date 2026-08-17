<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People and payroll: workers, the masters who submit for them, where they are
 * assigned, what they worked and what they need.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('kadrovi');

        Schema::create('radnici', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('origin_country')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('id_number')->nullable();
            $table->string('job_role')->nullable();
            // Bank details. `bank_account_status` is canonical, never a translated
            // label (the ISCILER ICIN BANKA HESAPLARI sheet uses Turkish/Serbian
            // wording).
            $table->string('bank_account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_status')->default('unknown'); // unknown | none | pending | open
            // Salary settings. Daily earned pay derives from these.
            $table->decimal('base_salary', 18, 2)->nullable();
            $table->string('salary_currency', 3)->default('EUR');
            $table->string('salary_period')->default('monthly');               // monthly | daily
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

        Schema::create('isplate_zarada', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('radnici')->restrictOnDelete();
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

        Schema::create('majstori', function (Blueprint $table) {
            $table->id();
            // The master is a worker; `user_id` links the app login that submits
            // attendance from the field (null while no account exists).
            $table->foreignId('employee_id')->unique()->constrained('radnici')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('korisnici')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
        });

        // Which workers are assigned to which site. Attendance is still recorded
        // per record, so this drives the daily-entry roster rather than
        // restricting it.
        Schema::create('radnik_gradiliste', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('radnici')->cascadeOnDelete();
            $table->foreignId('worksite_id')->constrained('gradilista')->cascadeOnDelete();
            $table->date('assigned_from')->nullable();
            $table->date('assigned_to')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->unique(['employee_id', 'worksite_id']);
        });

        // A master may cover more than one worksite, and a worksite more than one master.
        Schema::create('majstor_gradiliste', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_id')->constrained('majstori')->cascadeOnDelete();
            $table->foreignId('worksite_id')->constrained('gradilista')->cascadeOnDelete();
            $table->auditColumns();
            $table->timestamps();
            $table->unique(['master_id', 'worksite_id']);
        });

        Schema::create('podesavanja_radnih_dana', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique(); // first day of the month
            $table->unsignedSmallInteger('working_days');
            // An override of the default calendar has to say why.
            $table->string('reason');
            $table->auditColumns();
            $table->timestamps();
        });

        Schema::create('evidencija_prisustva', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('employee_id')->constrained('radnici')->restrictOnDelete();
            $table->foreignId('worksite_id')->constrained('gradilista')->restrictOnDelete();
            // Who submitted from the field (null when the office enters it directly).
            $table->foreignId('master_id')->nullable()->constrained('majstori')->nullOnDelete();
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
            $table->foreignId('submitted_by')->nullable()->constrained('korisnici')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('korisnici')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            // Daily earned pay: computed from the worker's salary settings and the
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
            // One record per worker per day, so a day can never be paid twice.
            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'worksite_id']);
            $table->index('approval_status');
            $table->index('master_id', 'evidencija_prisustva_master_id_index');
        });

        Schema::create('potrebe_radnika', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('radnici')->restrictOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained('gradilista')->nullOnDelete();
            $table->date('date');
            // equipment | document | salary_advance | travel | housing | medical | other
            $table->string('need_type');
            $table->text('description');
            $table->string('priority')->default('normal'); // low | normal | urgent
            $table->string('status')->default('open');     // open | in_review | resolved | rejected
            $table->foreignId('assigned_user_id')->nullable()->constrained('korisnici')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index('status');
            $table->index('priority');
            $table->index('date');
            $table->index('employee_id', 'potrebe_radnika_employee_id_index');
            $table->index('worksite_id', 'potrebe_radnika_worksite_id_index');
            $table->index('assigned_user_id', 'potrebe_radnika_assigned_user_id_index');
            // The archive: settled needs, newest first.
            $table->index(['status', 'resolved_at'], 'potrebe_radnika_status_resolved_at_index');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('kadrovi');

        Schema::dropIfExists('potrebe_radnika');
        Schema::dropIfExists('evidencija_prisustva');
        Schema::dropIfExists('podesavanja_radnih_dana');
        Schema::dropIfExists('majstor_gradiliste');
        Schema::dropIfExists('radnik_gradiliste');
        Schema::dropIfExists('majstori');
        Schema::dropIfExists('isplate_zarada');
        Schema::dropIfExists('radnici');
    }
};
