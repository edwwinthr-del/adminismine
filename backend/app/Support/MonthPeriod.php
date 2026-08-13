<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Month handling shared by every module that reports per month (salary,
 * attendance, working days, production): callers may pass 'YYYY-MM' or any date
 * inside the month and always get the month's first/last day back.
 */
class MonthPeriod
{
    /**
     * A month this class can actually parse.
     *
     * The old rule was `\d{4}-\d{2}`, which accepts months 00 through 99: every
     * endpoint taking a month answered `?month=2026-13` with a 500 from Carbon,
     * and `?month=2026-00` with December of the previous year, labelled as the
     * month that was asked for.
     */
    public const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])(-\d{2})?$/';

    /** The validation rule for a month parameter. */
    public static function rule(): string
    {
        return 'regex:'.self::PATTERN;
    }

    /** First day of the month, as Y-m-d. */
    public static function normalize(string $month): string
    {
        // Built from the year and month alone. Parsing the whole string let an
        // impossible day carry into the next month — `2026-02-31` became March.
        if (preg_match('/^(\d{4})-(\d{2})/', $month, $matches) === 1) {
            [, $year, $monthNumber] = $matches;

            if ((int) $monthNumber >= 1 && (int) $monthNumber <= 12) {
                return Carbon::create((int) $year, (int) $monthNumber, 1)->toDateString();
            }
        }

        return Carbon::parse($month)->startOfMonth()->toDateString();
    }

    /** Last day of the month, as Y-m-d. */
    public static function end(string $month): string
    {
        return Carbon::parse(self::normalize($month))->endOfMonth()->toDateString();
    }

    /** @return array{0: string, 1: string} first and last day of the month */
    public static function range(string $month): array
    {
        return [self::normalize($month), self::end($month)];
    }

    /** @return array{0: string, 1: string} first and last day of the year */
    public static function yearRange(int $year): array
    {
        return ["{$year}-01-01", "{$year}-12-31"];
    }

    /**
     * SQL that reduces a date column to 'YYYY-MM', so a month bucket can be
     * grouped by the database instead of by reading every row into PHP.
     *
     * Date truncation is the one place the two supported drivers genuinely
     * differ (the suite runs on SQLite, production on Postgres), so the
     * difference is spelled out once here rather than in each report.
     */
    public static function sqlMonth(string $driver, string $column): string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
