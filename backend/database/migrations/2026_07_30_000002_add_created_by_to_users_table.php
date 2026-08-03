<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who granted this login.
 *
 * Accounts are created by an administrator, never self-registered, and the app
 * now lets a Super Admin delete an account they granted. That permission has to
 * be answerable from the record itself rather than from memory, so the granting
 * user is stamped on the row the same way every financial table stamps its
 * author. `nullOnDelete` keeps the accounts a departed administrator created:
 * losing the grantor must never cascade into losing the logins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('is_active')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
