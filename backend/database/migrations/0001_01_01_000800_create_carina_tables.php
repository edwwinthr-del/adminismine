<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customs and transport paperwork.
 *
 * A schema of its own because it is the one table that spans the others: a CMR
 * points at a machine, at the invoice that bought it, at the shipment of ore it
 * carried and at whichever counterparty it was for. Filing it under finansije
 * or proizvodnja would put half its relationships in the wrong domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('carina');

        Schema::create('carinski_dokumenti', function (Blueprint $table) {
            $table->id();
            // cmr | customs_declaration | import_export | delivery_note | packing_list
            // | certificate_of_origin | goods_invoice | other
            $table->string('document_type')->default('cmr');
            $table->string('document_number')->nullable();
            $table->string('cmr_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('cmr_date')->nullable();
            $table->date('shipment_date')->nullable();
            // The customs agency the company pays: a supplier when registered,
            // free text otherwise, plus the invoice they billed for this shipment.
            $table->foreignId('customs_company_id')->nullable()->constrained('dobavljaci')->nullOnDelete();
            $table->string('customs_company_name')->nullable();
            $table->string('customs_invoice_number')->nullable();
            $table->string('sender')->nullable();
            $table->string('receiver')->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('goods_description')->nullable();
            $table->decimal('quantity', 18, 3)->nullable();
            $table->string('unit')->nullable();
            $table->string('origin_place')->nullable();
            $table->string('destination_place')->nullable();
            // Links. Machine + machine purchase invoice are the current priority;
            // the rest are there for shipments of goods.
            $table->foreignId('machine_id')->nullable()->constrained('masine')->nullOnDelete();
            $table->foreignId('payable_invoice_id')->nullable()->constrained('ulazne_fakture')->nullOnDelete();
            $table->foreignId('receivable_invoice_id')->nullable()->constrained('izlazne_fakture')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('klijenti')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('dobavljaci')->nullOnDelete();
            $table->foreignId('production_record_id')->nullable()->constrained('evidencija_proizvodnje')->nullOnDelete();
            // draft | received | checked | missing | completed | archived
            $table->string('status')->default('draft');
            $table->auditColumns();
            $table->timestamps();
            $table->index('document_type');
            $table->index('status');
            $table->index('cmr_number');
            $table->index('document_number');
            $table->index('shipment_date');
            $table->index('machine_id');
            $table->index('customs_company_id', 'carinski_dokumenti_customs_company_id_index');
            $table->index('payable_invoice_id', 'carinski_dokumenti_payable_invoice_id_index');
            $table->index('receivable_invoice_id', 'carinski_dokumenti_receivable_invoice_id_index');
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('carina');

        Schema::dropIfExists('carinski_dokumenti');
    }
};
