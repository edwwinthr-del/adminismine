<?php

namespace App\Services;

use App\Models\WorkingDaySetting;
use App\Support\CompanyConfig;
use App\Support\MonthPeriod;
use Illuminate\Support\Carbon;

/**
 * Working days per month, the divisor behind daily earned pay.
 *
 * Derived from the company's working-day rule — a six-day week (every day but
 * Sunday) unless configured otherwise — unless an authorized user stored an
 * override for that month.
 */
class WorkingDaysService
{
    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(private readonly CompanyConfig $config) {}

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

    /**
     * Days in the month that count as working days under the company's rule.
     *
     * A five-day company counting Saturdays as working days gets a divisor that
     * is simply wrong — every daily rate too low, every day of earned pay too
     * small — which is why this is a setting and not the `isSunday()` check it
     * used to be.
     */
    public function derivedForMonth(string $month): int
    {
        $start = Carbon::parse(MonthPeriod::normalize($month));
        $rule = $this->config->workingDayRule();
        $days = 0;

        for ($day = $start->copy(); $day->month === $start->month; $day->addDay()) {
            if ($this->counts($day, $rule)) {
                $days++;
            }
        }

        return $days;
    }

    private function counts(Carbon $day, string $rule): bool
    {
        return match ($rule) {
            'mon_fri' => ! $day->isWeekend(),
            'calendar' => true,
            default => ! $day->isSunday(),
        };
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
