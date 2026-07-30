<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mines and projects as first-class reference tables.
 *
 * The work structure is "single company, multi-site": a worksite is where people
 * clock in, a mine is the deposit being worked, and a project is the piece of
 * work being billed. They were previously collapsed into the worksite row (a
 * free-text `project_name`), which meant two sites on the same project could
 * spell it differently and no total could be trusted. Attendance, production and
 * machines all hang off a worksite, so pointing the worksite at a mine and a
 * project is what makes those filterable by either without touching their tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('location')->nullable();
            // What comes out of the ground here; production defaults to it.
            $table->string('material_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            // The client the work is billed to, when there is one.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('worksites', function (Blueprint $table) {
            $table->foreignId('mine_id')->nullable()->after('location')
                ->constrained('mines')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('mine_id')
                ->constrained('projects')->nullOnDelete();
        });

        $this->promoteProjectNames();

        Schema::table('worksites', function (Blueprint $table) {
            $table->dropColumn('project_name');
        });
    }

    /**
     * Every distinct `project_name` already typed on a worksite becomes a real
     * project row, and the worksite is pointed at it. Nothing a user entered is
     * discarded — the text is carried across before the column goes.
     */
    private function promoteProjectNames(): void
    {
        $names = DB::table('worksites')
            ->whereNotNull('project_name')
            ->where('project_name', '!=', '')
            ->distinct()
            ->pluck('project_name');

        foreach ($names as $name) {
            $id = DB::table('projects')->insertGetId([
                'name' => $name,
                'is_active' => true,
                'source' => 'migration',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('worksites')->where('project_name', $name)->update(['project_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('worksites', function (Blueprint $table) {
            $table->string('project_name')->nullable()->after('location');
        });

        // Row by row rather than an UPDATE ... JOIN, which the two drivers spell
        // differently.
        foreach (DB::table('projects')->get(['id', 'name']) as $project) {
            DB::table('worksites')->where('project_id', $project->id)
                ->update(['project_name' => $project->name]);
        }

        Schema::table('worksites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('mine_id');
        });

        Schema::dropIfExists('projects');
        Schema::dropIfExists('mines');
    }
};
