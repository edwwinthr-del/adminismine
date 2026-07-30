<?php

namespace App\Services;

use App\Models\WorkingDaySetting;
use App\Support\MonthPeriod;
use Illuminate\Support\Carbon;

/**
 * Working days per month, the divisor behind daily earned pay.
 *
 * Derived dynamically for a 6-day work week (every day except Sunday) unless an
 * authorized user stored an override for that month.
 */
class WorkingDaysService
{
    /** @var array<string, int> */
    private array $cache = [];

    public function forMonth(string $month): int
    {
        $key = MonthPeriod::normalize($month);

        return $this->cache[$key] ??= $this->resolve($key);
    }

    public function forDate(string $date): int
    {
        return $this->forMonth(Carbon::parse($date)->startOfMonth()->toDateString());
    }

    /** True when the month's value comes from a stored override. */
    public function isOverridden(string $month): bool
    {
        return $this->override(MonthPeriod::normalize($month)) !== null;
    }

    public function override(string $month): ?WorkingDaySetting
    {
        return WorkingDaySetting::query()
            ->whereDate('month', MonthPeriod::normalize($month))
            ->first();
    }

    /** Days in the month that are not Sundays. */
    public function derivedForMonth(string $month): int
    {
        $start = Carbon::parse(MonthPeriod::normalize($month));
        $days = 0;

        for ($day = $start->copy(); $day->month === $start->month; $day->addDay()) {
            if (! $day->isSunday()) {
                $days++;
            }
        }

        return $days;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function resolve(string $month): int
    {
        return $this->override($month)?->working_days ?? $this->derivedForMonth($month);
    }
}
