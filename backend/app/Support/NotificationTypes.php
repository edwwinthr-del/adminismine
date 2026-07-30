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
     * editable; `link` and `roles` are fixed properties of the type.
     *
     * @var array<string, array{timing: string, days_before: int|null, severity: string, link: string, roles: list<string>}>
     */
    public const TYPES = [
        'payables.unpaid' => [
            'timing' => 'weekly_summary',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/payables',
            'roles' => ['Admin'],
        ],
        'payables.overdue' => [
            'timing' => 'after_due',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/payables',
            'roles' => ['Admin'],
        ],
        'housing.rent_unpaid' => [
            'timing' => 'days_before',
            'days_before' => 3,
            'severity' => 'warning',
            'link' => '/housing',
            'roles' => ['Admin'],
        ],
        'housing.bills_overdue' => [
            'timing' => 'after_due',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/housing',
            'roles' => ['Admin'],
        ],
        'housing.contract_expiring' => [
            'timing' => 'days_before',
            'days_before' => 30,
            'severity' => 'info',
            'link' => '/housing',
            'roles' => ['Admin'],
        ],
        'salaries.unpaid' => [
            'timing' => 'monthly_summary',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/salaries',
            'roles' => ['Admin'],
        ],
        'attendance.unapproved' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/attendance',
            'roles' => ['Admin'],
        ],
        'attendance.overtime_pending' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/attendance',
            'roles' => ['Admin'],
        ],
        'worker_needs.urgent_open' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'critical',
            'link' => '/worker-needs',
            'roles' => ['Admin'],
        ],
        'mining.production_missing' => [
            'timing' => 'monthly_summary',
            'days_before' => null,
            'severity' => 'warning',
            'link' => '/mining',
            'roles' => ['Admin'],
        ],
        'machines.document_expiring' => [
            'timing' => 'days_before',
            'days_before' => 30,
            'severity' => 'warning',
            'link' => '/machines',
            'roles' => ['Admin'],
        ],
        'customs.incomplete' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/customs',
            'roles' => ['Admin'],
        ],
        'bank.unmatched' => [
            'timing' => 'weekly_summary',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/bank',
            'roles' => ['Admin'],
        ],
        'employees.missing_documents' => [
            'timing' => 'weekly_summary',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/workers',
            'roles' => ['Admin'],
        ],
        'employees.document_expiring' => [
            'timing' => 'days_before',
            'days_before' => 60,
            'severity' => 'warning',
            'link' => '/workers',
            'roles' => ['Admin'],
        ],
        // Not detected from data — an authorized user raises these by hand.
        'custom.reminder' => [
            'timing' => 'same_day',
            'days_before' => null,
            'severity' => 'info',
            'link' => '/notifications',
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
