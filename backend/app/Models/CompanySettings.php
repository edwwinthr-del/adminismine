<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Support\CompanyConfig;
use Illuminate\Database\Eloquent\Model;

class CompanySettings extends Model
{
    use HasAuditColumns;

    protected $table = 'podesavanja_kompanije';

    /**
     * Model-level defaults mirror the migration's column defaults so a freshly
     * created settings row is populated in memory too (not just in the DB).
     */
    protected $attributes = [
        'company_name' => 'AdminisMine DOO',
        'base_currency' => 'EUR',
        'default_locale' => 'en',
        'timezone' => 'Europe/Podgorica',
        'standard_day_hours' => 8,
        'overtime_multiplier' => 1.5,
        'working_day_rule' => 'every_non_sunday',
        'social_assistance_annual' => 1000,
    ];

    protected $fillable = [
        'company_name',
        'base_currency',
        'default_locale',
        'timezone',
        'tax_number',
        'address',
        'phone',
        'email',
        'logo_path',
        // The payroll rules the spec left open. They were constants in
        // DailyEarnedPayService, WorkingDaysService and SocialAssistanceService;
        // everything reads them through CompanyConfig now.
        'standard_day_hours',
        'overtime_multiplier',
        'working_day_rule',
        'social_assistance_annual',
        'enabled_modules',
        'profile',
        'work_structure_levels',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modules' => 'array',
            'work_structure_levels' => 'array',
            'standard_day_hours' => 'decimal:2',
            'overtime_multiplier' => 'decimal:2',
            'social_assistance_annual' => 'decimal:2',
        ];
    }

    /**
     * A rule changed through the API has to be in force for the rest of the
     * request — including the payroll recompute that follows it in the same
     * controller action, which would otherwise re-derive every figure from the
     * values it just replaced.
     */
    protected static function booted(): void
    {
        static::saved(fn () => app(CompanyConfig::class)->forget());
    }

    /**
     * The app is single-company: there is always exactly one settings row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
