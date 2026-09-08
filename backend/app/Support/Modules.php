<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\CustomsDocument;
use App\Models\Employee;
use App\Models\FlightTicket;
use App\Models\House;
use App\Models\Loan;
use App\Models\Machine;
use App\Models\Master;
use App\Models\Mine;
use App\Models\ProductionRecord;
use App\Models\Project;
use App\Models\SalaryPayment;
use App\Models\SocialAssistancePayment;
use App\Models\TravelExpense;
use App\Models\WorkerNeed;
use App\Models\Worksite;

/**
 * The parts of the app a company can decide it does not have.
 *
 * A freight forwarder buys payables, receivables, bank and customs and has no
 * use for a housing module; a construction firm has crews and sites but no
 * production log. Shipping one binary that shows every module to everyone makes
 * the app read as a list of things the customer is not doing.
 *
 * **A module is identified by the permissions it owns**, and that is the whole
 * trick: every report, lookup, import entity and notification type already
 * declares the named permission of the module behind it (rule 6), so the module
 * a thing belongs to is derivable rather than a second label to maintain. There
 * is no `module` key on four registries to keep in step with this one.
 *
 * What is **not** here is the core: identity and roles, company settings,
 * exchange rates, the bank ledger, payables, receivables, reports, the audit
 * log, notifications, imports and the dashboard. Those are what make it a
 * business system at all — money in, money out, who did it — and a toggle for
 * them would only ever be used by mistake.
 *
 * **Disabling hides; it never deletes.** Rows stay, foreign keys stay valid, and
 * re-enabling brings the module back with its history intact. That is what makes
 * the toggle safe enough to reach for.
 */
final class Modules
{
    /**
     * Every togglable module.
     *
     * - `permissions` — the named permissions this module owns. A permission
     *   belongs to exactly one module; that uniqueness is what lets a permission
     *   answer "which module?".
     * - `requires` — modules whose records this one points at with a **non-null**
     *   foreign key. Attendance without workers is a table of rows about nobody,
     *   so the combination is refused rather than allowed to half-work. A
     *   nullable link (a loan that may name a worker, a machine that may name a
     *   site) is not a requirement.
     * - `models` — what "how much is in here" means, for the confirmation the
     *   settings screen shows before something is switched off.
     *
     * @var array<string, array{permissions: list<string>, requires: list<string>, models: list<class-string>}>
     */
    public const MODULES = [
        'workers' => [
            'permissions' => ['employees.manage'],
            'requires' => [],
            'models' => [Employee::class],
        ],
        'worksites' => [
            'permissions' => ['worksites.manage'],
            'requires' => [],
            'models' => [Mine::class, Project::class, Worksite::class],
        ],
        'masters' => [
            'permissions' => ['masters.manage'],
            'requires' => ['workers', 'worksites'],
            'models' => [Master::class],
        ],
        'attendance' => [
            'permissions' => ['attendance.submit', 'attendance.approve', 'overtime.approve'],
            'requires' => ['workers', 'worksites'],
            'models' => [AttendanceRecord::class],
        ],
        'salaries' => [
            'permissions' => ['salary_payments.manage'],
            'requires' => ['workers'],
            'models' => [SalaryPayment::class],
        ],
        'worker_needs' => [
            'permissions' => ['worker_needs.manage'],
            'requires' => ['workers'],
            'models' => [WorkerNeed::class],
        ],
        'production' => [
            'permissions' => ['mining_production.submit', 'mining_production.approve'],
            'requires' => ['worksites'],
            'models' => [ProductionRecord::class],
        ],
        'machines' => [
            'permissions' => ['machines.manage'],
            'requires' => [],
            'models' => [Machine::class],
        ],
        'customs' => [
            'permissions' => ['customs_documents.manage'],
            'requires' => [],
            'models' => [CustomsDocument::class],
        ],
        'housing' => [
            'permissions' => ['housing.manage'],
            'requires' => ['workers'],
            'models' => [House::class],
        ],
        'travel' => [
            'permissions' => ['travel.manage'],
            'requires' => [],
            'models' => [FlightTicket::class, TravelExpense::class, SocialAssistancePayment::class],
        ],
        'loans' => [
            'permissions' => ['loans.manage'],
            'requires' => [],
            'models' => [Loan::class],
        ],
        'assistant' => [
            'permissions' => ['assistant.use'],
            'requires' => [],
            'models' => [],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function exists(string $module): bool
    {
        return array_key_exists($module, self::MODULES);
    }

    /**
     * The module a permission belongs to, or null when it belongs to the core.
     *
     * Built once per process: this is asked on every report list, every lookup
     * and every notification scan.
     */
    public static function forPermission(?string $permission): ?string
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (self::MODULES as $module => $definition) {
                foreach ($definition['permissions'] as $owned) {
                    $map[$owned] = $module;
                }
            }
        }

        return $permission === null ? null : ($map[$permission] ?? null);
    }

    /** @return list<string> modules that must be on for this one to be */
    public static function requirements(string $module): array
    {
        return self::MODULES[$module]['requires'] ?? [];
    }

    /**
     * Modules that require this one — what would break if it were switched off.
     *
     * @return list<string>
     */
    public static function dependents(string $module): array
    {
        return array_keys(array_filter(
            self::MODULES,
            static fn (array $definition): bool => in_array($module, $definition['requires'], true),
        ));
    }

    /**
     * How many records every module holds, for the confirmation shown before one
     * is hidden.
     *
     * Every module at once and in one query: the settings screen shows the count
     * beside each toggle, so asking per module meant sixteen `COUNT(*)` round
     * trips to paint one card. See {@see RowCounts}.
     *
     * @return array<string, int>
     */
    public static function recordCounts(): array
    {
        return RowCounts::forModels(array_map(
            static fn (array $definition): array => $definition['models'],
            self::MODULES,
        ));
    }

    /**
     * How many records one module holds.
     *
     * Prefer {@see recordCounts()} when more than one is wanted — this runs a
     * query per table of the module it is asked about.
     */
    public static function recordCount(string $module): int
    {
        $total = 0;

        foreach (self::MODULES[$module]['models'] ?? [] as $model) {
            $total += $model::query()->count();
        }

        return $total;
    }
}
