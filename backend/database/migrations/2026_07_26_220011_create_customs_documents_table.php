<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customs_documents', function (Blueprint $table) {
            $table->id();
            // cmr | customs_declaration | import_export | delivery_note | packing_list
            // | certificate_of_origin | goods_invoice | other
            $table->string('document_type')->default('cmr');
            $table->string('document_number')->nullable();
            $table->string('cmr_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('cmr_date')->nullable();
            $table->date('shipment_date')->nullable();

            // The customs agency AdminisMine pays: a supplier when registered,
            // free text otherwise, plus the invoice they billed for this shipment.
            $table->foreignId('customs_company_id')->nullable()->constrained('suppliers')->nullOnDelete();
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
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('payable_invoice_id')->nullable()->constrained('payable_invoices')->nullOnDelete();
            $table->foreignId('receivable_invoice_id')->nullable()->constrained('receivable_invoices')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('production_record_id')->nullable()->constrained('production_records')->nullOnDelete();

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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_documents');
    }
};
