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
    /** First day of the month, as Y-m-d. */
    public static function normalize(string $month): string
    {
        $value = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? "{$month}-01" : $month;

        return Carbon::parse($value)->startOfMonth()->toDateString();
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
