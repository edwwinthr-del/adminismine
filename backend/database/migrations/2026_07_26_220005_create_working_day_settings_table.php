<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Working days per month are normally derived (6-day week, Sundays off).
     * A row here is an authorized override for one month and always carries a reason.
     */
    public function up(): void
    {
        Schema::create('working_day_settings', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique(); // first day of the month
            $table->unsignedSmallInteger('working_days');
            $table->string('reason');
            $table->auditColumns();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_day_settings');
    }
};
