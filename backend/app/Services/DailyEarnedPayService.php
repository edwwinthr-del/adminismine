<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\MonthPeriod;

/**
 * Daily earned pay from salary settings + attendance (PROJECT_LLM_APP_PROMPT.md
 * "Daily Earned Pay"). Figures are cached on the attendance record so they stay
 * visible and auditable; the record is the only place they are written.
 */
class DailyEarnedPayService
{
    /** Hours in a standard working day; hours beyond this belong in overtime. */
    public const STANDARD_DAY_HOURS = 8.0;

    /** Used when the employee has no overtime multiplier or fixed hourly rate. */
    public const DEFAULT_OVERTIME_MULTIPLIER = 1.5;

    public function __construct(private readonly WorkingDaysService $workingDays) {}

    /** Recompute and persist the record's earned amounts. */
    public function apply(AttendanceRecord $record): AttendanceRecord
    {
        $employee = $record->employee ?? Employee::findOrFail($record->employee_id);
        $workingDays = $this->workingDays->forDate($record->date->toDateString());

        $computed = $this->compute($employee, $record, $workingDays);

        $record->forceFill($computed)->save();

        return $record;
    }

    /**
     * Recompute every attendance record in a month against the current divisor.
     *
     * The earned figures are cached on the record so they stay visible and
     * auditable, but a cache whose input can change silently is not a cache —
     * it is a wrong number. Overriding a month's working days changes the daily
     * rate for that whole month, and every day already entered kept the old
     * basis: on a 1,000 EUR monthly salary, setting July to 20 working days left
     * the days entered beforehand at 37.04 while the days entered afterwards
     * earned 50.00. Two workers with identical attendance were paid differently
     * depending on the order the office typed them in.
     *
     * The whole month is rewritten, approved rows included, because the divisor
     * is a statement about the month rather than about a row — leaving half of it
     * on the old basis is the inconsistency this is fixing. The count is returned
     * so the caller can record what the override touched.
     */
    public function recomputeMonth(string $month): int
    {
        $workingDays = $this->workingDays->forMonth($month);
        [$start, $end] = MonthPeriod::range($month);
        $recomputed = 0;

        AttendanceRecord::query()
            ->whereBetween('date', [$start, $end])
            ->with('employee')
            ->chunkById(200, function ($records) use ($workingDays, &$recomputed): void {
                foreach ($records as $record) {
                    if ($record->employee === null) {
                        continue;
                    }

                    $record->forceFill($this->compute($record->employee, $record, $workingDays))->save();
                    $recomputed++;
                }
            });

        return $recomputed;
    }

    /**
     * @return array{currency: string, daily_rate: float|null, working_days_basis: int, regular_amount: float, overtime_amount: float, total_amount: float}
     */
    public function compute(Employee $employee, AttendanceRecord $record, int $workingDays): array
    {
        $dailyRate = $employee->dailyRate($workingDays);
        $regular = $this->regularAmount($record, $dailyRate);
        $overtime = $this->overtimeAmount($employee, $record, $dailyRate);
        $adjustment = round((float) $record->adjustment_amount, 2);

        return [
            'currency' => $employee->salary_currency ?? 'EUR',
            'daily_rate' => $dailyRate,
            'working_days_basis' => $workingDays,
            'regular_amount' => $regular,
            'overtime_amount' => $overtime,
            'total_amount' => round($regular + $overtime + $adjustment, 2),
        ];
    }

    /**
     * Paid statuses earn the daily rate: a holiday counts as a full day, a worked
     * day is prorated by hours (capped at a standard day). Absence and leave earn nothing.
     */
    private function regularAmount(AttendanceRecord $record, ?float $dailyRate): float
    {
        if ($dailyRate === null || ! in_array($record->status, AttendanceRecord::PAID_STATUSES, true)) {
            return 0.0;
        }

        if ($record->status === 'holiday') {
            return round($dailyRate, 2);
        }

        $hours = $record->regular_hours === null
            ? self::STANDARD_DAY_HOURS
            : (float) $record->regular_hours;

        $factor = min($hours, self::STANDARD_DAY_HOURS) / self::STANDARD_DAY_HOURS;

        return round($dailyRate * $factor, 2);
    }

    /**
     * Overtime earns only on a day that earns at all.
     *
     * The status check lived in regularAmount() alone, so a day marked `absent`,
     * `sick_leave`, `unpaid_leave` or `other` paid nothing for the day and then
     * paid its overtime hours anyway — a worker recorded as absent could still
     * earn. The spec's rule is that those statuses pay nothing, full stop.
     */
    private function overtimeAmount(Employee $employee, AttendanceRecord $record, ?float $dailyRate): float
    {
        $hours = (float) $record->overtime_hours;

        if ($hours <= 0 || ! in_array($record->status, AttendanceRecord::PAID_STATUSES, true)) {
            return 0.0;
        }

        $hourlyRate = $employee->overtime_hourly_rate !== null
            ? (float) $employee->overtime_hourly_rate
            : $this->derivedOvertimeHourlyRate($employee, $dailyRate);

        if ($hourlyRate === null) {
            return 0.0;
        }

        return round($hourlyRate * $hours, 2);
    }

    private function derivedOvertimeHourlyRate(Employee $employee, ?float $dailyRate): ?float
    {
        if ($dailyRate === null) {
            return null;
        }

        $multiplier = $employee->overtime_multiplier !== null
            ? (float) $employee->overtime_multiplier
            : self::DEFAULT_OVERTIME_MULTIPLIER;

        return ($dailyRate / self::STANDARD_DAY_HOURS) * $multiplier;
    }
}
