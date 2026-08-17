<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money: what the company owes, what it is owed, what actually moved through
 * the accounts, and the loans on either side.
 *
 * Every table holding a foreign-currency amount carries the same five columns —
 * currency, the original amount, the rate, the rate's date and the EUR value —
 * because the original amount is never rewritten and the rate that produced the
 * EUR figure has to stay on the row (rule 5).
 *
 * Balances (paid/remaining/status) are cached aggregates recomputed from the
 * payment rows by the models' recalculate(), never user-edited (rule 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('finansije');

        Schema::create('ulazne_fakture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('dobavljaci')->restrictOnDelete();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('description')->nullable();
            $table->string('expense_category')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('original_amount', 18, 2);
            // Same precision as kursevi_valuta.rate, so a stored rate can be
            // written back without losing digits.
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            $table->auditColumns();
            $table->timestamps();
            $table->index('status');
            $table->index('invoice_date');
            $table->index('due_date');
            $table->index('supplier_id', 'ulazne_fakture_supplier_id_index');
            // Both the list's default sort and keyset paging over it.
            $table->index(['invoice_date', 'id'], 'ulazne_fakture_invoice_date_id_index');
            // `outstanding()`/`overdue()`: status first, then the due date test.
            $table->index(['status', 'due_date'], 'ulazne_fakture_status_due_date_index');
        });

        Schema::create('izlazne_fakture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('klijenti')->restrictOnDelete();
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('description')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('invoice_amount', 18, 2); // incl. VAT/PDV
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2)->default(0);
            // Cached, recomputed from payments received + deductions/offsets.
            $table->decimal('received_amount', 18, 2)->default(0);
            $table->decimal('deducted_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | partial | paid
            $table->auditColumns();
            $table->timestamps();
            $table->index('status');
            $table->index('invoice_date');
            $table->index('due_date');
            $table->index('client_id', 'izlazne_fakture_client_id_index');
            $table->index(['invoice_date', 'id'], 'izlazne_fakture_invoice_date_id_index');
            $table->index(['status', 'due_date'], 'izlazne_fakture_status_due_date_index');
            // The dashboard's "outstanding per client" group-by.
            $table->index(['status', 'client_id'], 'izlazne_fakture_status_client_id_index');
        });

        Schema::create('odbici_izlaznih_faktura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_invoice_id')->constrained('izlazne_fakture')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('deduction_date');
            $table->string('reason')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index('receivable_invoice_id', 'odbici_izlaznih_faktura_invoice_id_index');
        });

        Schema::create('bankovne_transakcije', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description_1')->nullable();
            $table->string('description_2')->nullable();
            // Signed per-account amounts (+ in / - out). A transfer moves between
            // two accounts within one row.
            $table->decimal('cash_amount', 18, 2)->default(0);
            $table->decimal('nlb_amount', 18, 2)->default(0);
            $table->decimal('lovcen_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('cash_amount_eur', 18, 2)->default(0);
            $table->decimal('nlb_amount_eur', 18, 2)->default(0);
            $table->decimal('lovcen_amount_eur', 18, 2)->default(0);
            $table->string('category')->nullable(); // income|expense|transfer|loan|payroll|housing|travel|other
            $table->foreignId('supplier_id')->nullable()->constrained('dobavljaci')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('klijenti')->nullOnDelete();
            $table->string('import_source')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index('date');
            $table->index('category');
            $table->index('supplier_id', 'bankovne_transakcije_supplier_id_index');
            $table->index('client_id', 'bankovne_transakcije_client_id_index');
            $table->index(['date', 'id'], 'bankovne_transakcije_date_id_index');
            // Exactly the duplicate signature, so the group-by behind the
            // duplicate flag is an index scan rather than a sort of the table.
            $table->index(
                ['date', 'cash_amount', 'nlb_amount', 'lovcen_amount'],
                'bankovne_transakcije_signature_index',
            );
        });

        // Polymorphic settlements: invoices, rent, utility bills, tickets, travel
        // expenses and loan repayments all morph here, which is what lets any of
        // them be matched to a bank/cash movement.
        Schema::create('placanja', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2)->default(0);
            $table->date('payment_date');
            $table->string('method'); // cash | nlb | lovcen | other
            // Either the movement this payment was typed off (matched), or the one
            // booked from it (booked) — never rewritten in the first case.
            $table->foreignId('bank_transaction_id')->nullable()
                ->constrained('bankovne_transakcije')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index('payment_date');
            // `unmatched()` asks "does any payment point at this movement?" for
            // every row of the bank list; without this it is a scan per row.
            $table->index('bank_transaction_id', 'placanja_bank_transaction_id_index');
        });

        // North-Ex style loans and advances. Repayments are ordinary `placanja`
        // rows, so a repayment can be matched to a movement exactly like an
        // invoice settlement — which is why there is no separate repayments table.
        Schema::create('pozajmice', function (Blueprint $table) {
            $table->id();
            $table->string('counterparty');
            // Whose money it is: `received` = the company borrowed and owes it
            // back, `given` = the company lent it out and is owed.
            $table->string('direction')->default('received'); // received | given
            $table->string('reference_number')->nullable();
            $table->date('loan_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('original_amount', 18, 2);
            $table->decimal('exchange_rate', 18, 10)->nullable(); // 1 EUR = rate <currency>
            $table->date('exchange_rate_date')->nullable();
            $table->decimal('amount_eur', 18, 2);
            // Cached, recomputed from the linked repayments.
            $table->decimal('repaid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);
            $table->string('status')->default('outstanding'); // outstanding | partial | repaid
            $table->foreignId('supplier_id')->nullable()->constrained('dobavljaci')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('klijenti')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('radnici')->nullOnDelete();
            $table->auditColumns();
            $table->timestamps();
            $table->index('counterparty');
            $table->index('loan_date');
            $table->index('status');
            $table->index('direction');
            $table->index('reference_number');
            $table->index('employee_id', 'pozajmice_employee_id_index');
            $table->index('supplier_id', 'pozajmice_supplier_id_index');
            $table->index('client_id', 'pozajmice_client_id_index');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('finansije');

        Schema::dropIfExists('pozajmice');
        Schema::dropIfExists('placanja');
        Schema::dropIfExists('bankovne_transakcije');
        Schema::dropIfExists('odbici_izlaznih_faktura');
        Schema::dropIfExists('izlazne_fakture');
        Schema::dropIfExists('ulazne_fakture');
    }
};
