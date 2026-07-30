<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The spec's "machine registration/insurance reminders (if configured)"
     * needs dates to remind about — machines only carried paperwork as files
     * until now. Both are optional: a machine without them is never reminded on.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->date('registration_expiry')->nullable()->after('status');
            $table->date('insurance_expiry')->nullable()->after('registration_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['registration_expiry', 'insurance_expiry']);
        });
    }
};
