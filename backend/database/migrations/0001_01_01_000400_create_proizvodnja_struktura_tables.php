<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The work structure: mine -> project -> worksite.
 *
 * A `rudnik` is the deposit, a `projekat` is the billed work and a
 * `gradiliste` is where people clock in — and the worksite is the only one that
 * records point at. Both keys are nullOnDelete so losing a project can never
 * take a site, and its history, with it.
 *
 * Split from the rest of `proizvodnja` only because of ordering: production and
 * machines reference kadrovi and finansije, which are created after this.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('proizvodnja');

        Schema::create('rudnici', function (Blueprint $table) {
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

        Schema::create('projekti', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            // The client the work is billed to, when there is one.
            $table->foreignId('client_id')->nullable()->constrained('klijenti')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('gradilista', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->foreignId('mine_id')->nullable()->constrained('rudnici')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projekti')->nullOnDelete();
            // Optional link to the client the site works for (e.g. Uniprom).
            $table->foreignId('client_id')->nullable()->constrained('klijenti')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('proizvodnja');

        Schema::dropIfExists('gradilista');
        Schema::dropIfExists('projekti');
        Schema::dropIfExists('rudnici');
    }
};
