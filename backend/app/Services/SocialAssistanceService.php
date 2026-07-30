<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SocialAssistancePayment;
use Illuminate\Support\Collection;

/**
 * Social assistance is an annual entitlement per worker. Payments are recorded
 * one row at a time; what is still payable ("ODENEBILIR" in the workbook) is
 * always derived here, never stored.
 *
 * The entitlement default is a domain decision the spec left open — change it
 * here rather than ad hoc at call sites.
 */
class SocialAssistanceService
{
    /** EUR per worker per year, matching the workbook's 1000 per worker. */
    public const DEFAULT_ANNUAL_ENTITLEMENT = 1000.0;

    public function entitlementFor(int $year): float
    {
        return self::DEFAULT_ANNUAL_ENTITLEMENT;
    }

    /**
     * Paid vs still payable per worker for a year. Workers with no payment yet
     * are included so the office can see who has not been paid at all.
     *
     * @return array{year: int, entitlement: float, rows: list<array<string, mixed>>, totals: array<string, float|int>}
     */
    public function summary(int $year, ?int $employeeId = null): array
    {
        $entitlement = $this->entitlementFor($year);

        $payments = SocialAssistancePayment::query()
            ->forYear($year)
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->get();

        $employees = Employee::query()
            ->when($employeeId !== null, fn ($query) => $query->where('id', $employeeId))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $rows = $employees->map(function (Employee $employee) use ($payments, $entitlement): array {
            $paid = round((float) $payments->where('employee_id', $employee->id)->sum('amount_eur'), 2);

            return [
                'employee_id' => $employee->id,
                'full_name' => $employee->full_name,
                'paid' => $paid,
                // Never negative: paying more than the entitlement is allowed
                // (an authorized exception), it just leaves nothing payable.
                'payable' => round(max($entitlement - $paid, 0), 2),
                'payment_count' => $payments->where('employee_id', $employee->id)->count(),
            ];
        });

        // Imported rows whose worker has no employee record yet still have to
        // show up somewhere, grouped by the name as it was written.
        $unmatched = $payments->whereNull('employee_id')
            ->groupBy(fn (SocialAssistancePayment $payment): string => (string) $payment->person_name)
            ->map(fn (Collection $group, string $name): array => [
                'employee_id' => null,
                'full_name' => $name === '' ? null : $name,
                'paid' => round((float) $group->sum('amount_eur'), 2),
                'payable' => null, // no worker record, so no entitlement to measure against
                'payment_count' => $group->count(),
            ])->values();

        $all = $rows->concat($unmatched)->values();

        return [
            'year' => $year,
            'entitlement' => $entitlement,
            'rows' => $all->all(),
            'totals' => [
                'paid' => round((float) $payments->sum('amount_eur'), 2),
                'payable' => round((float) $rows->sum('payable'), 2),
                'workers_paid' => $payments->pluck('employee_id')->filter()->unique()->count(),
                'payment_count' => $payments->count(),
            ],
        ];
    }
}
