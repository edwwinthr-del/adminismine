<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Framework plumbing: queue, cache, sessions and tokens. No business meaning,
 * which is exactly why it is kept out of the domain schemas.
 *
 * The table names are Serbian like everywhere else; Laravel finds them through
 * config (config/cache.php, config/queue.php, config/session.php,
 * config/auth.php) and Sanctum through App\Models\PersonalAccessToken.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sistem');

        Schema::create('kes', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('kes_zakljucavanja', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('poslovi', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('grupe_poslova', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('neuspeli_poslovi', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });

        Schema::create('sesije', function (Blueprint $table) {
            $table->string('id')->primary();
            // Indexed but deliberately unconstrained: a session row outliving the
            // account it belonged to is harmless, and the alternative is a write
            // to bezbednost on every session GC.
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('tokeni_za_reset_lozinke', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pristupni_tokeni', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('sistem');

        Schema::dropIfExists('pristupni_tokeni');
        Schema::dropIfExists('tokeni_za_reset_lozinke');
        Schema::dropIfExists('sesije');
        Schema::dropIfExists('neuspeli_poslovi');
        Schema::dropIfExists('grupe_poslova');
        Schema::dropIfExists('poslovi');
        Schema::dropIfExists('kes_zakljucavanja');
        Schema::dropIfExists('kes');
    }
};
