<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-wide reference data: the one company row, the exchange rates every
 * conversion is priced from, and the two counterparty registers.
 *
 * These are the tables other domains point at rather than duplicate, which is
 * why they sit in a schema of their own instead of inside finansije.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::create('podesavanja_kompanije', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('AdminisMine DOO');
            $table->string('base_currency', 3)->default('EUR');
            $table->string('default_locale', 5)->default('en');
            $table->string('timezone')->default('Europe/Podgorica');
            $table->string('tax_number')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->auditColumns();
            $table->timestamps();
        });

        Schema::create('kursevi_valuta', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3)->default('EUR');
            $table->string('quote_currency', 3);
            // Convention: 1 base = `rate` quote  (e.g. 1 EUR = 35 TRY).
            $table->decimal('rate', 20, 10);
            $table->date('rate_date');
            $table->string('provider')->nullable();
            $table->boolean('is_manual')->default(false);
            // A manual override has to say why (rule 5).
            $table->text('override_reason')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->auditColumns();
            $table->timestamps();
            // Named explicitly: the generated name would run past Postgres'
            // 63-character identifier limit and be silently truncated.
            $table->unique(['base_currency', 'quote_currency', 'rate_date'], 'kursevi_valuta_par_datum_unique');
        });

        Schema::create('dobavljaci', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('iban')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
            $table->index('name');
        });

        Schema::create('klijenti', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('iban')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditColumns();
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::dropIfExists('klijenti');
        Schema::dropIfExists('dobavljaci');
        Schema::dropIfExists('kursevi_valuta');
        Schema::dropIfExists('podesavanja_kompanije');
    }
};
