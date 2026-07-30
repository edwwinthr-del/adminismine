<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the uploader said they were importing.
 *
 * Null keeps meaning "read the whole workbook and match each sheet to a parser",
 * which is how the original GLOBAL MINE workbook is imported. A value means the
 * file was uploaded as a filled-in template for that one entity, and is read by
 * its header row instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->string('entity')->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropColumn('entity');
        });
    }
};
