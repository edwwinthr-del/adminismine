<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_records', function (Blueprint $table) {
            $table->id();
            // daily rows carry a `date`; monthly rows only a `period_month`. Both
            // always store `period_month` so month/year totals are one query.
            $table->string('period_type')->default('daily'); // daily | monthly
            $table->date('date')->nullable();
            $table->date('period_month');

            $table->foreignId('worksite_id')->constrained('worksites')->restrictOnDelete();
            // The mining engineer who reported it (an employee, not necessarily a login).
            $table->foreignId('engineer_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('material_type')->default('bauxite_ore'); // canonical, never a translated label
            $table->decimal('quantity', 18, 3);
            $table->string('unit')->default('tons');
            $table->string('quality_grade')->nullable();
            $table->string('attachment_path')->nullable();

            // draft | approved | rejected — a record is editable until approved.
            $table->string('approval_status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            $table->auditColumns();
            $table->timestamps();

            $table->index(['period_month', 'worksite_id']);
            $table->index('date');
            $table->index('material_type');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_records');
    }
};
