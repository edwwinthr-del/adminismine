<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tickets are bought in Turkey and paid in TRY, but the books are kept in
        // EUR — so every row carries the original amount, the rate used and the
        // resulting EUR value (rule 5: store the rate used on each conversion).
        Schema::create('flight_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // Kept for imported rows whose worker has no employee record yet.
            $table->string('passenger_name')->nullable();
            $table->date('ticket_date');
            $table->string('direction'); // arrival | departure | round_trip
            $table->string('route')->nullable();
            $table->string('airline')->nullable();
            $table->string('reference')->nullable(); // PNR / ticket number
            $table->string('currency', 3)->default('TRY');
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate', 18, 10)->nullable(); // 1 EUR = rate <currency>
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2);
            // Settlement, always in EUR (cached, recomputed from the payments).
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            // The workbook's YAZILDI / YAZILMADI: whether the cost has been
            // written against the worker's travel account yet.
            $table->string('cost_status')->default('not_written'); // written | not_written
            $table->auditColumns();
            $table->timestamps();

            $table->index('ticket_date');
            $table->index(['employee_id', 'ticket_date']);
            $table->index('status');
            $table->index('cost_status');
        });

        Schema::create('travel_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('person_name')->nullable();
            $table->date('expense_date');
            // First day of the month the cost is booked into — the workbook's
            // "YAZILAN YOL MASRAFI" is reported per month, not per day.
            $table->date('period_month');
            $table->string('expense_type'); // car | flight | bus | taxi | fuel | accommodation | meal | other
            // A written flight cost points back at the ticket it came from.
            $table->foreignId('flight_ticket_id')->nullable()->constrained('flight_tickets')->nullOnDelete();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate', 18, 10)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            $table->string('cost_status')->default('not_written'); // written | not_written
            $table->auditColumns();
            $table->timestamps();

            $table->index('expense_date');
            $table->index(['employee_id', 'period_month']);
            $table->index('expense_type');
            $table->index('status');
        });

        // Social assistance is an entitlement per worker per year: the rows are
        // the payments made, and what is still payable is computed from them
        // against the entitlement (rule 1 — never a stored editable balance).
        Schema::create('social_assistance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('person_name')->nullable();
            $table->date('payment_date');
            $table->unsignedSmallInteger('entitlement_year');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate', 18, 10)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2);
            $table->string('method')->nullable(); // cash | nlb | lovcen | other
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->string('reason')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index(['employee_id', 'entitlement_year']);
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_assistance_payments');
        Schema::dropIfExists('travel_expenses');
        Schema::dropIfExists('flight_tickets');
    }
};
