<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The conversation. Kept per user because the assistant only ever sees
        // data that user is allowed to see.
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            // What the model decided to do, and what came back.
            $table->string('intent')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('ai_suggestion_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });

        // A proposed change. Nothing here is applied until the user confirms it
        // (rule 2: the LLM never silently mutates financial data).
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind'); // create_record
            $table->string('target'); // utility_bill | payable_invoice | …
            // What the model proposed, and the validated version that would be saved.
            $table->json('proposed');
            $table->json('validated')->nullable();
            $table->json('errors')->nullable();
            $table->string('status')->default('pending'); // pending | confirmed | rejected | invalid
            $table->text('prompt')->nullable();
            $table->nullableMorphs('record');
            $table->timestamp('confirmed_at')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
        Schema::dropIfExists('assistant_messages');
    }
};
