<?php

namespace App\Support;

/**
 * The catalogue of things the app can notify about — one entry per type in the
 * spec's notification list. This is the single source of truth: the rule seeder,
 * the scanner's detector lookup and the API's validation all read it.
 *
 * `link` is the frontend page a notification points at; the record id is
 * appended by Notification::linkPath() when the notification has a subject.
 */
class NotificationTypes
{
    public const TIMINGS = ['same_day', 'days_before', 'after_due', 'weekly_summary', 'monthly_summary'];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const CHANNELS = ['in_app'];

    public const STATUSES = ['unread', 'read', 'dismissed', 'resolved'];

    /** Statuses a notification can still act on; anything else is history. */
    public const OPEN_STATUSES = ['unread', 'read'];

    /**
     * type => defaults. `timing`/`days_before`/`severity` seed the rule and stay
     * editable; `link`, `permission` and `roles` are fixed properties of the type.
     *
     * `permission` is the named permission that gates the module the
     * notification is about, and it is a floor no rule can lower: a
     * notification's `data` carries supplier names, invoice numbers and amounts,
     * so delivering one to somebody who cannot open the page behind it hands
     * them figures the app otherwise refuses them. Whoever configures a rule
     * chooses among people who may already see the data, never grants sight of
     * it (rule 6). Null means the type carries no module data — only
     * `custom.reminder`, which is the author's own words to a named person.
     *
     * @var array<string, array{timing: string, days_before: int|null, severity: string, link: string, permission: string|null, roles: list<string>}>
     */
    public const TYPES = [
        'payables.unpaid' => [
            'timing' => 'weekly_summary',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/payables',
            'permission' => 'payables.view',
            'roles' => ['Admin'],
        ],
        'payables.overdue' => [
            'timing' => 'after_due',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/payables',
            'permission' => 'payables.view',
            'roles' => ['Admin'],
        ],
        'housing.rent_unpaid' => [
            'timing' => 'days_before',
            'days_before' => 3,
            'severity' => 'warning',
            'link' => '/housing',
            'permission' => 'housing.manage',
            'roles' => ['Admin'],
        ],
        'housing.bills_overdue' => [
            'timing' => 'after_due',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/housing',
            'permission' => 'housing.manage',
            'roles' => ['Admin'],
        ],
        'housing.contract_expiring' => [
            'timing' => 'days_before',
            'days_before' => 30,
            'severity' => 'info',
            'link' => '/housing',
            'permission' => 'housing.manage',
            'roles' => ['Admin'],
        ],
        'salaries.unpaid' => [
            'timing' => 'monthly_summary',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/salaries',
            'permission' => 'salary_payments.manage',
            'roles' => ['Admin'],
        ],
        'attendance.unapproved' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/attendance',
            'permission' => 'attendance.approve',
            'roles' => ['Admin'],
        ],
        'attendance.overtime_pending' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/attendance',
            'permission' => 'attendance.approve',
            'roles' => ['Admin'],
        ],
        'worker_needs.urgent_open' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'critical',
            'link' => '/worker-needs',
            'permission' => 'worker_needs.manage',
            'roles' => ['Admin'],
        ],
        'mining.production_missing' => [
            'timing' => 'monthly_summary',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/mining',
            'permission' => 'mining_production.submit',
            'roles' => ['Admin'],
        ],
        'machines.document_expiring' => [
            'timing' => 'days_before',
            'days_before' => 30,
            'severity' => 'warning',
            'link' => '/machines',
            'permission' => 'machines.manage',
            'roles' => ['Admin'],
        ],
        'customs.incomplete' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/customs',
            'permission' => 'customs_documents.manage',
            'roles' => ['Admin'],
        ],
        'bank.unmatched' => [
            'timing' => 'weekly_summary',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/bank',
            'permission' => 'bank_transactions.manage',
            'roles' => ['Admin'],
        ],
        'employees.missing_documents' => [
            'timing' => 'weekly_summary',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/workers',
            'permission' => 'employees.manage',
            'roles' => ['Admin'],
        ],
        'employees.document_expiring' => [
            'timing' => 'days_before',
            'days_before' => 60,
            'severity' => 'warning',
            'link' => '/workers',
            'permission' => 'employees.manage',
            'roles' => ['Admin'],
        ],
        // Not detected from data — an authorized user raises these by hand.
        'custom.reminder' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/notifications',
            'permission' => null,
            'roles' => ['Admin'],
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    /** Types the scanner generates; `custom.reminder` is raised by a user instead. */
    public static function scannable(): array
    {
        return array_values(array_diff(self::all(), ['custom.reminder']));
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return array{timing: string, days_before: int|null, severity: string, link: string, roles: list<string>} */
    public static function defaults(string $type): array
    {
        return self::TYPES[$type];
    }

    /** The permission a recipient must hold to be told about this type, if any. */
    public static function permission(string $type): ?string
    {
        return self::TYPES[$type]['permission'] ?? null;
    }

    public static function link(string $type): string
    {
        return self::TYPES[$type]['link'] ?? '/notifications';
    }

    /** Timings that collapse every candidate into a single periodic summary. */
    public static function isSummary(string $timing): bool
    {
        return in_array($timing, ['weekly_summary', 'monthly_summary'], true);
    }
}
