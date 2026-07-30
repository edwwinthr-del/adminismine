<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('project_name')->nullable();
            // Optional link to the client the site works for (e.g. Uniprom).
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();

            $table->index('name');
        });

        // Which employees work at which site. Attendance is still recorded per
        // record, so this drives the daily-entry roster rather than restricting it.
        Schema::create('employee_worksite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('worksite_id')->constrained('worksites')->cascadeOnDelete();
            $table->date('assigned_from')->nullable();
            $table->date('assigned_to')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->unique(['employee_id', 'worksite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_worksite');
        Schema::dropIfExists('worksites');
    }
};
