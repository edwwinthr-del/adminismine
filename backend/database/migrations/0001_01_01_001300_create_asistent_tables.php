<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The LLM assistant: the conversation, and the changes it proposes.
 *
 * Nothing in `ai_predlozi` is applied until the user confirms it — the model may
 * suggest, classify and prepare, but never mutates financial data on its own
 * (rule 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('asistent');

        // Kept per user because the assistant only ever sees data that user is
        // allowed to see.
        Schema::create('poruke_asistenta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('korisnici')->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            // What the model decided to do, and what came back.
            $table->string('intent')->nullable();
            $table->json('data')->nullable();
            // Deliberately unconstrained: the message is written as the reply is
            // streamed, before the suggestion row it may end up pointing at.
            $table->foreignId('ai_suggestion_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });

        Schema::create('ai_predlozi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('korisnici')->cascadeOnDelete();
            $table->string('kind');   // create_record
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
        DbSchema::useSchema('asistent');

        Schema::dropIfExists('ai_predlozi');
        Schema::dropIfExists('poruke_asistenta');
    }
};
