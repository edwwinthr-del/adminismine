<?php

use App\Support\DbSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll rules stop being constants in three services.
 *
 * A standard day of 8 hours, overtime at 1.5×, a working week of every day but
 * Sunday and 1,000 EUR of social assistance a year are this company's rules, not
 * arithmetic — they were the spec's open questions, answered once in
 * `DailyEarnedPayService`, `WorkingDaysService` and `SocialAssistanceService`.
 * The next company of the same shape answers at least one of them differently,
 * and a company on a five-day week gets a divisor that is simply wrong.
 *
 * The defaults below reproduce today's behaviour exactly, so an install that
 * never opens the settings screen is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::table('podesavanja_kompanije', function (Blueprint $table): void {
            // Hours in a standard working day; hours beyond this are overtime.
            $table->decimal('standard_day_hours', 5, 2)->default(8);
            // Used when a worker has no multiplier and no fixed hourly rate.
            $table->decimal('overtime_multiplier', 5, 2)->default(1.5);
            // Which days of the month count as working days — the divisor behind
            // every daily rate. Canonical value, never a translated label (rule 4).
            $table->string('working_day_rule')->default('every_non_sunday');
            // Social assistance entitlement per worker per year.
            $table->decimal('social_assistance_annual', 12, 2)->default(1000);
        });
    }

    public function down(): void
    {
        DbSchema::useSchema('sifarnici');

        Schema::table('podesavanja_kompanije', function (Blueprint $table): void {
            $table->dropColumn([
                'standard_day_hours',
                'overtime_multiplier',
                'working_day_rule',
                'social_assistance_annual',
            ]);
        });
    }
};
