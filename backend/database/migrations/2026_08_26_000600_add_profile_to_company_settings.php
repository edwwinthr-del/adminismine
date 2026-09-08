<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shape of company this install is set up as.
 *
 * `profile` records the answer rather than deriving it: everything a profile
 * sets — modules, terminology, vocabularies, payroll rules — is separately
 * editable afterwards, so a company that applied `construction` and then changed
 * four things is still a construction install, and the screen should say so.
 *
 * `work_structure_levels` is the one genuinely structural setting. The hierarchy
 * stays three levels in the database whatever this says (mine → project →
 * worksite, with both parent keys nullable); a two-level company simply never
 * creates the top one, and this is what stops the app asking for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::table('podesavanja_kompanije', function (Blueprint $table): void {
            // Null until somebody sets the install up — which is what the setup
            // prompt keys on.
            $table->string('profile')->nullable();
            // Null means all three, so an existing install is unchanged.
            $table->json('work_structure_levels')->nullable();
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::table('podesavanja_kompanije', function (Blueprint $table): void {
            $table->dropColumn(['profile', 'work_structure_levels']);
        });
    }
};
