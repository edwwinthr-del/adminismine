<?php

use App\Models\Machine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One attachment table for every record that carries paperwork (machines,
 * customs documents, and later housing/travel), replacing machine_attachments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('kind')->default('other'); // invoice | warranty | customs | cmr | photo | other
            $table->string('label')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id', 'kind'], 'file_attachments_attachable_kind_index');
        });

        if (Schema::hasTable('machine_attachments')) {
            $rows = DB::table('machine_attachments')->get();

            foreach ($rows as $row) {
                DB::table('file_attachments')->insert([
                    'attachable_type' => Machine::class,
                    'attachable_id' => $row->machine_id,
                    'kind' => $row->kind,
                    'label' => $row->label,
                    'file_path' => $row->file_path,
                    'original_name' => $row->original_name,
                    'mime_type' => $row->mime_type,
                    'size_bytes' => $row->size_bytes,
                    'created_by' => $row->created_by,
                    'updated_by' => $row->updated_by,
                    'source' => $row->source,
                    'notes' => $row->notes,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            Schema::drop('machine_attachments');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('file_attachments');
    }
};
