<?php

namespace App\Support;

use App\Models\CompanySettings;
use App\Services\DailyEarnedPayService;
use App\Services\SocialAssistanceService;

/**
 * The company's own answers to the questions the spec left open.
 *
 * A standard day of 8 hours, overtime at 1.5×, a six-day working week and 1,000
 * EUR of social assistance a year were constants in three services. They are not
 * arithmetic — they are this company's rules, and the next company of the same
 * shape answers at least one of them differently. Everything that used to read a
 * constant reads this instead.
 *
 * **Memoised per request, not cached across them.** The settings row is one row
 * read a handful of times while computing a month of payroll, so holding it for
 * the life of the request is enough; a shared cache would buy nothing and add an
 * invalidation seam between processes. `CompanySettings::saved()` clears it, so
 * a rule changed through the API is in force for the rest of that request.
 *
 * Every getter falls back to the service constant that used to hold the value,
 * so a null column — an install migrated but never configured — behaves exactly
 * as the app did before this existed.
 */
final class CompanyConfig
{
    /**
     * How a month's working days are counted. Canonical values, rendered by the
     * frontend (rule 4).
     *
     * - `every_non_sunday` — six-day week; what this company has always used.
     * - `mon_fri` — five-day week.
     * - `calendar` — every day of the month.
     *
     * A "six-day week" is not a fourth entry: it is `every_non_sunday` under
     * another name, and two values that compute the same divisor are two ways
     * for two installs to disagree about what they mean.
     */
    public const WORKING_DAY_RULES = ['every_non_sunday', 'mon_fri', 'calendar'];

    /**
     * The work structure, coarsest first: the deposit, the billed job, the place
     * people clock in.
     *
     * All three exist in the database whatever a company uses — both parent keys
     * are nullable, so a two-level company simply never creates the top one.
     * Nothing here is a schema decision.
     */
    public const WORK_STRUCTURE_LEVELS = ['mine', 'project', 'worksite'];

    private ?CompanySettings $settings = null;

    public function settings(): CompanySettings
    {
        return $this->settings ??= CompanySettings::current();
    }

    /** Drop the memoised row. Called whenever the settings are written. */
    public function forget(): void
    {
        $this->settings = null;
    }

    /** Hours in a standard working day; hours beyond this belong in overtime. */
    public function standardDayHours(): float
    {
        $hours = (float) $this->settings()->standard_day_hours;

        // A zero would make the proration divide by nothing, and is not a day
        // anyone works.
        return $hours > 0 ? $hours : DailyEarnedPayService::STANDARD_DAY_HOURS;
    }

    /** Used when a worker has no multiplier and no fixed overtime hourly rate. */
    public function overtimeMultiplier(): float
    {
        $multiplier = (float) $this->settings()->overtime_multiplier;

        return $multiplier > 0 ? $multiplier : DailyEarnedPayService::DEFAULT_OVERTIME_MULTIPLIER;
    }

    /** Which days of the month count as working days. */
    public function workingDayRule(): string
    {
        $rule = (string) $this->settings()->working_day_rule;

        return in_array($rule, self::WORKING_DAY_RULES, true) ? $rule : 'every_non_sunday';
    }

    /** Social assistance entitlement per worker per year, in the accounting currency. */
    public function socialAssistanceAnnual(): float
    {
        $entitlement = (float) $this->settings()->social_assistance_annual;

        return $entitlement > 0 ? $entitlement : SocialAssistanceService::DEFAULT_ANNUAL_ENTITLEMENT;
    }

    /**
     * The modules this company has, as a set.
     *
     * Null in the column means every module — an install that never opened the
     * toggles, and a module added in a later release, are both on rather than
     * silently missing. An unknown key in a stored list is ignored rather than
     * honoured: a module removed from the catalogue should not keep switching
     * something off.
     *
     * @return list<string>
     */
    public function enabledModules(): array
    {
        $stored = $this->settings()->enabled_modules;

        if (! is_array($stored)) {
            return Modules::keys();
        }

        return array_values(array_filter(
            array_map('strval', $stored),
            static fn (string $module): bool => Modules::exists($module),
        ));
    }

    /** Whether a module exists for this company. The core is always enabled. */
    public function moduleEnabled(string $module): bool
    {
        if (! Modules::exists($module)) {
            // Not a togglable module, so it is core and always on. Being lenient
            // here is deliberate: a typo'd middleware argument should not 404 a
            // route that belongs to the core.
            return true;
        }

        return in_array($module, $this->enabledModules(), true);
    }

    /**
     * Whether the module behind a named permission is enabled.
     *
     * This is what every registry filters on. A permission the core owns has no
     * module and is always enabled, which covers `custom.reminder` and every
     * report, lookup and import entity belonging to payables, receivables or the
     * bank ledger.
     */
    public function permissionEnabled(?string $permission): bool
    {
        $module = Modules::forPermission($permission);

        return $module === null || $this->moduleEnabled($module);
    }

    /**
     * Which shape of company this install was set up as, or null where nobody
     * has set it up yet — which is what the setup prompt keys on.
     *
     * Recorded rather than derived: everything a profile chose stays editable
     * afterwards, so an install that applied `construction` and then changed
     * four things is still a construction install.
     */
    public function profile(): ?string
    {
        $profile = $this->settings()->profile;

        return is_string($profile) && $profile !== '' ? $profile : null;
    }

    /**
     * The levels of mine → project → worksite this company uses.
     *
     * Null means all three, so an existing install is unchanged. `worksite` is
     * always present whatever is stored: every module that records anything
     * points at one, so an install without it would have nowhere to put a day of
     * attendance.
     *
     * @return list<string>
     */
    public function workStructureLevels(): array
    {
        $stored = $this->settings()->work_structure_levels;

        if (! is_array($stored)) {
            return self::WORK_STRUCTURE_LEVELS;
        }

        $levels = array_values(array_filter(
            array_map('strval', $stored),
            static fn (string $level): bool => in_array($level, self::WORK_STRUCTURE_LEVELS, true),
        ));

        return in_array('worksite', $levels, true) ? $levels : [...$levels, 'worksite'];
    }

    public function structureLevelEnabled(string $level): bool
    {
        return in_array($level, $this->workStructureLevels(), true);
    }

    /**
     * The columns that change what a day of work is worth.
     *
     * Kept here rather than in the controller because the controller's only
     * question is "did any of these move" — the list of what counts as a payroll
     * rule belongs with the rules.
     *
     * @return list<string>
     */
    public static function payrollRuleColumns(): array
    {
        return ['standard_day_hours', 'overtime_multiplier', 'working_day_rule'];
    }
}
