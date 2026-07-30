<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;

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

    private function overtimeAmount(Employee $employee, AttendanceRecord $record, ?float $dailyRate): float
    {
        $hours = (float) $record->overtime_hours;

        if ($hours <= 0) {
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
