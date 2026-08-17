<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the worksites produce, and the machines that do it.
 *
 * Both carry `worksite_id` alone and reach their mine and project through it,
 * so reassigning a site to another project stays a one-row edit instead of a
 * rewrite of every record it ever produced.
 *
 * Separated from the work structure only by ordering: production points at a
 * worker (kadrovi) and a machine at its purchase paperwork (finansije).
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('proizvodnja');

        Schema::create('evidencija_proizvodnje', function (Blueprint $table) {
            $table->id();
            // Daily rows carry a `date`; monthly rows only a `period_month`. Both
            // always store `period_month` so month/year totals are one query.
            $table->string('period_type')->default('daily'); // daily | monthly
            $table->date('date')->nullable();
            $table->date('period_month');
            $table->foreignId('worksite_id')->constrained('gradilista')->restrictOnDelete();
            // The mining engineer who reported it (a worker, not necessarily a login).
            $table->foreignId('engineer_id')->nullable()->constrained('radnici')->nullOnDelete();
            $table->string('material_type')->default('bauxite_ore'); // canonical, never a translated label
            $table->decimal('quantity', 18, 3);
            $table->string('unit')->default('tons');
            $table->string('quality_grade')->nullable();
            $table->string('attachment_path')->nullable();
            // draft | approved | rejected — a record is editable until approved.
            $table->string('approval_status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('korisnici')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index(['period_month', 'worksite_id']);
            $table->index('date');
            $table->index('material_type');
            $table->index('approval_status');
        });

        Schema::create('masine', function (Blueprint $table) {
            $table->id();
            $table->string('machine_type'); // excavator, truck, loader, …
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            // Where it was bought: a known supplier when possible, a free-text
            // seller otherwise (private sales, auctions).
            $table->date('purchase_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('dobavljaci')->nullOnDelete();
            $table->string('seller_name')->nullable();
            $table->string('purchase_invoice_number')->nullable();
            $table->decimal('purchase_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            // The paper trail for the purchase, when it exists in the app already.
            $table->foreignId('payable_invoice_id')->nullable()->constrained('ulazne_fakture')->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bankovne_transakcije')->nullOnDelete();
            $table->string('current_location')->nullable();
            $table->foreignId('worksite_id')->nullable()->constrained('gradilista')->nullOnDelete();
            $table->string('status')->default('active'); // active | maintenance | inactive | sold
            $table->date('registration_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->auditColumns();
            $table->timestamps();
            $table->index('status');
            $table->index('machine_type');
            $table->index('worksite_id');
            $table->index('supplier_id', 'masine_supplier_id_index');
            $table->index('payable_invoice_id', 'masine_payable_invoice_id_index');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('proizvodnja');

        Schema::dropIfExists('masine');
        Schema::dropIfExists('evidencija_proizvodnje');
    }
};
