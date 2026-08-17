<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker housing: the rented houses, who lives in them, the rent and utility
 * bills, and the exception path where a worker carries part of the cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('smestaj');

        Schema::create('kuce', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            // The landlord ("house holder") the company pays the rent to.
            $table->string('landlord_name')->nullable();
            $table->string('landlord_phone')->nullable();
            $table->string('landlord_id_number')->nullable();
            $table->string('landlord_bank_account')->nullable();
            $table->decimal('monthly_rent', 18, 2)->nullable();
            $table->decimal('deposit', 18, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->unsignedTinyInteger('rent_due_day')->nullable(); // day of month rent is due
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
            $table->index('name');
            $table->index('is_active');
        });

        // Dated occupancy history: a worker may move between houses mid-month, so
        // rows are never overwritten — the previous stay is closed with a
        // move-out date.
        Schema::create('useljenja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained('kuce')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('radnici')->restrictOnDelete();
            $table->string('room')->nullable();
            $table->date('moved_in_at');
            $table->date('moved_out_at')->nullable(); // null = currently living there
            $table->auditColumns();
            $table->timestamps();
            $table->index(['house_id', 'moved_out_at']);
            $table->index(['employee_id', 'moved_out_at']);
        });

        Schema::create('placanja_kirije', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained('kuce')->restrictOnDelete();
            $table->date('month'); // first day of the month
            $table->string('currency', 3)->default('EUR');
            $table->decimal('rent_amount_due', 18, 2);
            // Cached, recomputed from the linked payments.
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            // Rent is a company expense by default; anything else is an exception
            // and must carry a reason.
            $table->string('cost_bearer')->default('company'); // company | workers
            $table->string('exception_reason')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->unique(['house_id', 'month']);
            $table->index('status');
        });

        Schema::create('rezijski_racuni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_id')->constrained('kuce')->restrictOnDelete();
            // electricity | water | internet | heating | garbage | maintenance | other
            $table->string('bill_type');
            $table->date('billing_period'); // first day of the billed month
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('EUR');
            $table->date('due_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            $table->string('cost_bearer')->default('company'); // company | workers
            $table->string('exception_reason')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index(['house_id', 'billing_period']);
            $table->index('status');
            $table->index('bill_type');
            // The housing tiles: this month's bills, then the overdue ones.
            $table->index(['billing_period', 'status'], 'rezijski_racuni_period_status_index');
        });

        // The exception path: charging a worker for part of the housing cost.
        // Never created automatically — an authorized user must give a reason.
        Schema::create('odbici_za_smestaj', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('radnici')->restrictOnDelete();
            $table->foreignId('house_id')->constrained('kuce')->restrictOnDelete();
            $table->date('month');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('rent_share', 18, 2)->default(0);
            $table->decimal('utility_share', 18, 2)->default(0);
            $table->decimal('amount_deducted', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('reason'); // why the worker carries this cost
            $table->foreignId('utility_bill_id')->nullable()->constrained('rezijski_racuni')->nullOnDelete();
            $table->auditColumns();
            $table->timestamps();
            $table->index(['employee_id', 'month']);
            $table->index(['house_id', 'month']);
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('smestaj');

        Schema::dropIfExists('odbici_za_smestaj');
        Schema::dropIfExists('rezijski_racuni');
        Schema::dropIfExists('placanja_kirije');
        Schema::dropIfExists('useljenja');
        Schema::dropIfExists('kuce');
    }
};
