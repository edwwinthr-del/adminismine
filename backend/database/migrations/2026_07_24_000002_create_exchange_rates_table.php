<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3)->default('EUR');
            $table->string('quote_currency', 3);
            // Convention: 1 base = `rate` quote  (e.g. 1 EUR = 35 TRY).
            $table->decimal('rate', 20, 10);
            $table->date('rate_date');
            $table->string('provider')->nullable();
            $table->boolean('is_manual')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
