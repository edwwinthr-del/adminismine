<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which parts of the app this company decided it has.
 *
 * Null rather than a seeded list, and null means everything: an install that
 * never opens the toggles behaves exactly as it always did, and a module added
 * to the catalogue in a later release is on by default rather than invisible
 * until every existing install edits its settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::table('podesavanja_kompanije', function (Blueprint $table): void {
            $table->json('enabled_modules')->nullable();
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::table('podesavanja_kompanije', function (Blueprint $table): void {
            $table->dropColumn('enabled_modules');
        });
    }
};
