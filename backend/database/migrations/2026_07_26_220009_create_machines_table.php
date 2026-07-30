<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('machine_type');            // excavator, truck, loader, …
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();

            // Where it was bought: a known supplier when possible, a free-text
            // seller otherwise (private sales, auctions).
            $table->date('purchase_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('seller_name')->nullable();
            $table->string('purchase_invoice_number')->nullable();
            $table->decimal('purchase_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('EUR');

            // The paper trail for the purchase, when it exists in the app already.
            $table->foreignId('payable_invoice_id')->nullable()->constrained('payable_invoices')->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();

            $table->string('current_location')->nullable();
            $table->foreignId('worksite_id')->nullable()->constrained('worksites')->nullOnDelete();
            $table->string('status')->default('active'); // active | maintenance | inactive | sold

            $table->auditColumns();
            $table->timestamps();

            $table->index('status');
            $table->index('machine_type');
            $table->index('worksite_id');
        });

        // Invoices, warranties, customs papers and photos belonging to a machine.
        Schema::create('machine_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->string('kind')->default('other'); // invoice | warranty | customs | photo | other
            $table->string('label')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index(['machine_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_attachments');
        Schema::dropIfExists('machines');
    }
};
