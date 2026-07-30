<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained('worksites')->nullOnDelete();
            $table->date('date');
            // equipment | document | salary_advance | travel | housing | medical | other
            $table->string('need_type');
            $table->text('description');
            $table->string('priority')->default('normal'); // low | normal | urgent
            $table->string('status')->default('open');     // open | in_review | resolved | rejected
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_needs');
    }
};
