<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One uploaded workbook. A batch is parsed first and only written to the
        // real tables once someone has looked at the preview and approved it.
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('status')->default('previewed'); // previewed | imported | cancelled | failed
            $table->json('sheet_summary')->nullable(); // per-sheet counts as parsed
            $table->json('totals')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index('status');
        });

        // One row of one sheet. `raw` keeps the cells exactly as they were read
        // so the original is always recoverable; `mapped` is what would be saved.
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            // Preserved verbatim, per the spec — the sheet label is not normalized.
            $table->string('sheet_name');
            $table->unsignedInteger('row_number');
            $table->string('target'); // payable_invoice | bank_transaction | employee | …
            $table->json('raw');
            $table->json('mapped')->nullable();
            $table->json('issues')->nullable(); // incomplete fields, duplicate hints
            $table->string('action')->default('create'); // create | skip
            $table->string('status')->default('pending'); // pending | imported | skipped | failed
            // What the row became, once imported.
            $table->nullableMorphs('record');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'sheet_name']);
            $table->index(['import_batch_id', 'status']);
            $table->index('target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
