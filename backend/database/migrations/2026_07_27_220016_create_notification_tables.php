<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per notification type: whether it fires, when, how loudly and
        // who hears about it. Seeded from NotificationTypes, then edited by an
        // Admin — the rows are configuration, not user data.
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->boolean('is_enabled')->default(true);
            // same_day | days_before | after_due | weekly_summary | monthly_summary
            $table->string('timing')->default('same_day');
            $table->unsignedSmallInteger('days_before')->nullable();
            $table->string('severity')->default('info'); // info | warning | critical
            $table->json('channels'); // ['in_app'] for now; email etc. is Phase Two
            // Recipients by role name and/or by user id. Both empty means nobody
            // is notified, which is how a rule is muted without disabling it.
            $table->json('recipient_roles');
            $table->json('recipient_user_ids');
            $table->json('config')->nullable(); // per-type knobs (thresholds, windows)
            $table->auditColumns();
            $table->timestamps();

            $table->index('is_enabled');
        });

        // A generated notification, one row per recipient. Nothing here is a
        // translated string: the frontend renders `type` + `data` through i18n,
        // so the same row reads correctly in en/sr/tr.
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_rule_id')->nullable()->constrained('notification_rules')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('severity')->default('info');
            // The record this is about. Null for summaries and custom reminders.
            $table->nullableMorphs('subject');
            $table->json('data')->nullable(); // params for the translated message
            $table->date('due_date')->nullable();
            // Bucket a summary belongs to (first day of the week/month).
            $table->date('period')->nullable();
            $table->string('status')->default('unread'); // unread | read | dismissed | resolved
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // Identifies the underlying issue, so a re-scan updates rather than
            // duplicates, and a cleared condition can be auto-resolved.
            $table->string('dedupe_key');
            $table->auditColumns();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'status']);
            $table->index('type');
        });

        // Per-user opt-out. `is_enabled` is tri-state: null follows the rule.
        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->boolean('is_enabled')->nullable();
            $table->json('channels')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_rules');
    }
};
