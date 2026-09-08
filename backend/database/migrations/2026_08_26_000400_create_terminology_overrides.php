<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this company calls the things the app models.
 *
 * A construction firm has sites, not mines; a facility-services company has
 * foremen, not majstori. The app's *structure* is right for all of them — a
 * deposit, a billed job, a place people clock in — and only the words are
 * wrong, so only the words are configurable.
 *
 * This overrides **labels and nothing else** (rule 4). The route stays `/mines`,
 * the table stays `rudnici` and the permission stays `worksites.manage` whatever
 * the screen says, because renaming any of those would break the API contract
 * and the audit trail to buy a word.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::create('prevodi_pojmova', function (Blueprint $table): void {
            $table->id();
            // An i18n key from the frontend dictionary, and only one on
            // App\Support\Terminology::OVERRIDABLE — the domain nouns, never
            // "Save" or "Cancel".
            $table->string('key');
            $table->string('locale', 5);
            $table->string('value');
            $table->auditColumns();
            $table->timestamps();
            // One word per term per language. Removing an override is deleting
            // the row, which is how a term goes back to the built-in wording.
            $table->unique(['key', 'locale'], 'prevodi_pojmova_key_locale_unique');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::dropIfExists('prevodi_pojmova');
    }
};
