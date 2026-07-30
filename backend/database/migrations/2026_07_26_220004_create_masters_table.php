<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masters', function (Blueprint $table) {
            $table->id();
            // The master is an employee; `user_id` links the app login that submits
            // attendance from the field (nullable while the account is not created).
            $table->foreignId('employee_id')->unique()->constrained('employees')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
        });

        // A master may cover more than one worksite, and a worksite more than one master.
        Schema::create('master_worksite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_id')->constrained('masters')->cascadeOnDelete();
            $table->foreignId('worksite_id')->constrained('worksites')->cascadeOnDelete();
            $table->auditColumns();
            $table->timestamps();

            $table->unique(['master_id', 'worksite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_worksite');
        Schema::dropIfExists('masters');
    }
};
