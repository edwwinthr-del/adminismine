<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SalaryPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalaryObligationService
{
    /**
     * Build the monthly salary obligations for a month from the active employees'
     * salary settings. Existing rows are never touched (an obligation the office
     * already adjusted stays as it is) and employees without a usable monthly
     * base salary are reported as skipped instead of guessed at.
     *
     * @return array{month: string, created: Collection<int, SalaryPayment>, skipped: list<array{employee_id: int, full_name: string, reason: string}>, existing: list<int>}
     */
    public function generate(string $month, bool $preview = false): array
    {
        $month = SalaryPayment::normalizeMonth($month);

        $employees = Employee::query()->active()->orderBy('last_name')->get();

        $existingEmployeeIds = SalaryPayment::query()
            ->forMonth($month)
            ->pluck('employee_id')
            ->all();

        $skipped = [];
        $toCreate = [];

        foreach ($employees as $employee) {
            if (in_array($employee->id, $existingEmployeeIds, true)) {
                continue;
            }

            if ($employee->salary_period !== 'monthly') {
                $skipped[] = $this->skip($employee, 'not_monthly_salary');

                continue;
            }

            if ($employee->base_salary === null || (float) $employee->base_salary <= 0) {
                $skipped[] = $this->skip($employee, 'missing_base_salary');

                continue;
            }

            $toCreate[] = [
                'employee_id' => $employee->id,
                'salary_month' => $month,
                'currency' => $employee->salary_currency,
                'base_salary' => (float) $employee->base_salary,
                'source' => 'generated',
            ];
        }

        if ($preview) {
            $created = collect($toCreate)->map(function (array $row) use ($employees): SalaryPayment {
                $obligation = new SalaryPayment($row);
                $obligation->setRelation('employee', $employees->firstWhere('id', $row['employee_id']));
                $obligation->forceFill([
                    'net_salary_due' => $row['base_salary'],
                    'paid_amount' => 0,
                    'remaining_amount' => $row['base_salary'],
                    'status' => 'unpaid',
                ]);

                return $obligation;
            });

            return [
                'month' => $month,
                'created' => $created,
                'skipped' => $skipped,
                'existing' => $existingEmployeeIds,
            ];
        }

        $created = DB::transaction(function () use ($toCreate): Collection {
            return collect($toCreate)->map(function (array $row): SalaryPayment {
                $obligation = SalaryPayment::create($row);
                $obligation->recalculate();

                return $obligation->load('employee');
            });
        });

        return [
            'month' => $month,
            'created' => $created,
            'skipped' => $skipped,
            'existing' => $existingEmployeeIds,
        ];
    }

    /** @return array{employee_id: int, full_name: string, reason: string} */
    private function skip(Employee $employee, string $reason): array
    {
        return [
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'reason' => $reason,
        ];
    }
}
