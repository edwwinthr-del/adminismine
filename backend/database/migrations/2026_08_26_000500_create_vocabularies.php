<?php

use App\Support\DbSchema;
use App\Support\Vocabulary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The open-ended lists become rows.
 *
 * `material_type` shipped as `bauxite_ore | overburden | limestone | other` — a
 * constant in a PHP class, so a construction firm recording cubic metres of
 * concrete had to have the app rebuilt to say so. Nothing in the codebase
 * branches on any of these values; they are defaults and labels, which is
 * exactly what makes them safe to hand over.
 *
 * The values the app ships with are seeded as `is_system`, so an install that
 * never opens the editor is unchanged, and the importer's label normaliser
 * (`ARABA` → `car`) keeps having somewhere to land.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::create('recnici', function (Blueprint $table): void {
            $table->id();
            $table->string('vocabulary');
            // Canonical snake_case, whatever it is called on screen (rule 4).
            $table->string('value');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Shipped with the app: deactivatable, never deletable.
            $table->boolean('is_system')->default(false);
            $table->auditColumns();
            $table->timestamps();
            $table->unique(['vocabulary', 'value'], 'recnici_vocabulary_value_unique');
            $table->index(['vocabulary', 'is_active'], 'recnici_vocabulary_active_index');
        });

        $now = now();
        $rows = [];

        foreach (Vocabulary::CATALOGUE as $vocabulary => $definition) {
            foreach (array_values($definition['defaults']) as $order => $value) {
                $rows[] = [
                    'vocabulary' => $vocabulary,
                    'value' => $value,
                    // Seeded in the order the constant listed them, which is the
                    // order every form already offered.
                    'sort_order' => $order,
                    'is_active' => true,
                    'is_system' => true,
                    'source' => 'migration',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('recnici')->insert($rows);
    }

    public function down(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::dropIfExists('recnici');
    }
};
